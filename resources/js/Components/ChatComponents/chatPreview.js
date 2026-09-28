export const parseChatMetadata = (metadata) => {
    try {
        if (!metadata) return {};
        const parsed = typeof metadata === 'string' ? JSON.parse(metadata) : metadata;
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
};

const extensionByMime = {
    'text/plain': 'TXT',
    'application/pdf': 'PDF',
    'application/vnd.ms-powerpoint': 'PPT',
    'application/msword': 'DOC',
    'application/vnd.ms-excel': 'XLS',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOCX',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PPTX',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
};

export const documentExtension = (chat) => {
    const metadata = parseChatMetadata(chat?.metadata);
    const mimeType = chat?.media?.type
        || metadata.document?.mime_type
        || metadata.media?.mime_type;

    return mimeType ? extensionByMime[mimeType] || 'Unknown' : null;
};
