const IMAGE_COMPRESSION_THRESHOLD = 512 * 1024;
const AUDIO_COMPRESSION_THRESHOLD = 4 * 1024 * 1024;
const VIDEO_COMPRESSION_THRESHOLD = 12 * 1024 * 1024;
const MAX_IMAGE_WIDTH = 1920;
const MAX_IMAGE_HEIGHT = 1920;

let ffmpeg = null;
let ffmpegLoadPromise = null;

const extensionFor = (file) => {
    const fromName = file.name?.split('.').pop()?.toLowerCase();

    if (fromName && /^[a-z0-9]+$/.test(fromName)) {
        return fromName;
    }

    return {
        'video/mp4': 'mp4',
        'video/quicktime': 'mov',
        'video/webm': 'webm',
        'audio/mpeg': 'mp3',
        'audio/mp3': 'mp3',
        'audio/ogg': 'ogg',
        'audio/wav': 'wav',
        'audio/mp4': 'm4a',
    }[file.type] || 'bin';
};

const outputName = (file, extension) => {
    const baseName = file.name?.replace(/\.[^/.]+$/, '') || 'media';
    return `${baseName}-compressed.${extension}`;
};

const fileFromBytes = (bytes, name, type, originalFile) => new File([bytes], name, {
    type,
    lastModified: originalFile.lastModified || Date.now(),
});

const isAlreadyOptimized = (file, mediaType, options = {}) => {
    if (options.skipCompression) {
        return true;
    }

    if (mediaType === 'image') {
        return file.size <= IMAGE_COMPRESSION_THRESHOLD;
    }

    if (mediaType === 'audio') {
        const isCompressedAudio = [
            'audio/mpeg',
            'audio/mp3',
            'audio/ogg',
            'audio/aac',
            'audio/mp4',
        ].includes(file.type);

        return isCompressedAudio && file.size <= AUDIO_COMPRESSION_THRESHOLD;
    }

    return file.type === 'video/mp4' && file.size <= VIDEO_COMPRESSION_THRESHOLD;
};

const loadFfmpeg = async () => {
    if (ffmpeg) {
        return ffmpeg;
    }

    if (!ffmpegLoadPromise) {
        ffmpegLoadPromise = (async () => {
            const [ffmpegModule, coreModule, wasmModule] = await Promise.all([
                import('@ffmpeg/ffmpeg'),
                import('../../../node_modules/@ffmpeg/core/dist/esm/ffmpeg-core.js?url'),
                import('../../../node_modules/@ffmpeg/core/dist/esm/ffmpeg-core.wasm?url'),
            ]);
            const { FFmpeg } = ffmpegModule;
            const instance = new FFmpeg();

            await instance.load({
                coreURL: coreModule.default,
                wasmURL: wasmModule.default,
            });

            ffmpeg = instance;
            return instance;
        })().catch((error) => {
            ffmpegLoadPromise = null;
            throw error;
        });
    }

    return ffmpegLoadPromise;
};

const compressImage = async (file) => {
    let image;
    try {
        if (typeof createImageBitmap === 'function') {
            image = await createImageBitmap(file);
        } else {
            const objectUrl = URL.createObjectURL(file);
            image = await new Promise((resolve, reject) => {
                const element = new Image();
                element.onload = () => {
                    URL.revokeObjectURL(objectUrl);
                    resolve(element);
                };
                element.onerror = (error) => {
                    URL.revokeObjectURL(objectUrl);
                    reject(error);
                };
                element.src = objectUrl;
            });
        }

        const scale = Math.min(
            1,
            MAX_IMAGE_WIDTH / (image.width || image.naturalWidth),
            MAX_IMAGE_HEIGHT / (image.height || image.naturalHeight),
        );
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round((image.width || image.naturalWidth) * scale));
        canvas.height = Math.max(1, Math.round((image.height || image.naturalHeight) * scale));
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);

        const outputType = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
        const outputExtension = outputType === 'image/png' ? 'png' : 'jpg';
        const blob = await new Promise((resolve) => {
            canvas.toBlob(resolve, outputType, 0.82);
        });

        if (!blob || blob.size >= file.size) {
            return file;
        }

        return new File([blob], outputName(file, outputExtension), {
            type: outputType,
            lastModified: file.lastModified || Date.now(),
        });
    } finally {
        image?.close?.();
    }
};

const compressWithFfmpeg = async (file, mediaType, onProgress) => {
    const instance = await loadFfmpeg();
    const inputName = `input.${extensionFor(file)}`;
    const extension = mediaType === 'video' ? 'mp4' : 'mp3';
    const outputFileName = `output.${extension}`;

    const progressHandler = ({ progress }) => {
        onProgress?.(Math.max(0, Math.min(100, Math.round(progress * 100))));
    };

    instance.on('progress', progressHandler);

    try {
        await instance.writeFile(inputName, new Uint8Array(await file.arrayBuffer()));

        const command = mediaType === 'video'
            ? [
                '-i', inputName,
                '-vf', 'scale=min(1280\,iw):min(1280\,ih):force_original_aspect_ratio=decrease:force_divisible_by=2',
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '28',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-movflags', '+faststart',
                outputFileName,
            ]
            : [
                '-i', inputName,
                '-vn',
                '-c:a', 'libmp3lame',
                '-b:a', '96k',
                outputFileName,
            ];

        await instance.exec(command);
        const bytes = await instance.readFile(outputFileName);
        const mimeType = mediaType === 'video' ? 'video/mp4' : 'audio/mpeg';

        if (!bytes?.length || bytes.length >= file.size) {
            return file;
        }

        return fileFromBytes(bytes, outputName(file, extension), mimeType, file);
    } finally {
        instance.off('progress', progressHandler);
        await instance.deleteFile(inputName).catch(() => {});
        await instance.deleteFile(outputFileName).catch(() => {});
    }
};

/**
 * Compresses only media that is likely to benefit from transcoding before the
 * upload request starts. Small, already-compressed files bypass FFmpeg so the
 * common send path stays fast. If a browser cannot process a format, the
 * original file is returned so sending still works.
 */
export const compressMedia = async (file, mediaType, onProgress, options = {}) => {
    if (!file || !['image', 'video', 'audio'].includes(mediaType)) {
        return file;
    }

    if (isAlreadyOptimized(file, mediaType, options)) {
        return file;
    }

    try {
        if (mediaType === 'image') {
            return await compressImage(file);
        }

        return await compressWithFfmpeg(file, mediaType, onProgress);
    } catch (error) {
        console.warn('Media compression failed; sending the original file.', error);
        return file;
    }
};
