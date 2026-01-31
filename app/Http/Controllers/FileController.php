<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FileController extends Controller
{
    public function show($filename)
    {
        $path = storage_path('app/' . $filename);

        if (!file_exists($path)) {
            Log::warning('FileController: missing file', ['requested' => $filename, 'path' => $path]);
            abort(404);
        }

        $file = \File::get($path);
        $type = \File::mimeType($path);

        if ($type === 'application/octet-stream') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $map = [
                'aac' => 'audio/aac',
                'amr' => 'audio/amr',
                'mp3' => 'audio/mpeg',
                'm4a' => 'audio/mp4',
                'mp4' => 'audio/mp4',
                'ogg' => 'audio/ogg; codecs=opus',
                'wav' => 'audio/wav',
            ];

            if (isset($map[$ext])) {
                Log::info('FileController: inferred mime from extension', ['path' => $path, 'extension' => $ext, 'inferred' => $map[$ext]]);
                $type = $map[$ext];
            } else {
                Log::info('FileController: mime is application/octet-stream and extension not mapped', ['path' => $path, 'extension' => $ext]);
            }
        }

        Log::info('FileController: serving file', ['path' => $path, 'mime' => $type]);

        // If mime type is generic, try to infer from extension for audio files
        if ($type === 'application/octet-stream') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $map = [
                'aac' => 'audio/aac',
                'amr' => 'audio/amr',
                'mp3' => 'audio/mpeg',
                'm4a' => 'audio/mp4',
                'mp4' => 'audio/mp4',
                'ogg' => 'audio/ogg; codecs=opus',
                'wav' => 'audio/wav',
            ];

            if (isset($map[$ext])) {
                $type = $map[$ext];
            }
        }

        $response = \Response::make($file, 200);
        $response->header("Content-Type", $type);

        return $response;
    }
}
