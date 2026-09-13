<?php

namespace App\Services\ChatbotStudio;

use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class SourceReader
{
    public const MAX_BYTES = 180000;

    public function fromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'chatbot.chatberry.click'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || !preg_match('~^/[A-Za-z0-9_-]{1,64}/?$~', $parts['path'] ?? '')) {
            throw ValidationException::withMessages(['url' => 'Use a public https://chatbot.chatberry.click/ simulator link.']);
        }
        try {
            $response = Http::timeout(25)->connectTimeout(10)->withOptions([
            'allow_redirects' => false,
            'progress' => static function ($total, $downloaded): void {
                if ($total > 2 * 1024 * 1024 || $downloaded > 2 * 1024 * 1024) {
                    throw ValidationException::withMessages(['url' => 'The simulator exceeds the 2 MB download limit.']);
                }
            },
            ])->get($url);
        } catch (ConnectionException $e) {
            throw ValidationException::withMessages(['url' => 'The simulator could not be reached. Retry or upload its HTML.']);
        }
        if (!$response->successful()) {
            throw ValidationException::withMessages(['url' => 'Could not read the simulator. Upload its HTML or paste its content.']);
        }
        return $this->html($response->body());
    }

    public function fromFile(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'pdf') {
            try {
                $config = new \Smalot\PdfParser\Config();
                $config->setRetainImageContent(false);
                $config->setDecodeMemoryLimit(8 * 1024 * 1024);
                $text = (new \Smalot\PdfParser\Parser([], $config))->parseFile($file->getRealPath())->getText();
            } catch (\Exception $e) {
                throw ValidationException::withMessages(['file' => 'The PDF could not be read. Upload an unencrypted document with selectable text.']);
            }
            if (trim($text) === '') {
                throw ValidationException::withMessages(['file' => 'This PDF has no readable text. Paste its text or upload a text-based document.']);
            }
            return $this->bounded($text);
        }
        if ($extension === 'docx') {
            $zip = new ZipArchive();
            if ($zip->open($file->getRealPath()) !== true) {
                throw ValidationException::withMessages(['file' => 'Invalid DOCX file.']);
            }
            try {
                $stat = $zip->statName('word/document.xml');
                if (!$stat || $stat['size'] > 2 * 1024 * 1024) {
                    throw ValidationException::withMessages(['file' => 'DOCX document is missing or too large.']);
                }
                $doc = $this->document($zip->getFromName('word/document.xml'), false);
                $paragraphs = [];
                foreach ($doc->getElementsByTagName('p') as $paragraph) {
                    $paragraphs[] = $paragraph->textContent;
                }
                return $this->bounded(implode("\n", $paragraphs));
            } finally {
                $zip->close();
            }
        }
        if (!in_array($extension, ['txt', 'md', 'json', 'html', 'htm'], true)) {
            throw ValidationException::withMessages(['file' => 'Supported files: TXT, MD, JSON, HTML, PDF and DOCX.']);
        }
        $content = file_get_contents($file->getRealPath());
        return in_array($extension, ['html', 'htm'], true) ? $this->html($content) : $this->bounded($content);
    }

    public function html(string $html): string
    {
        $document = $this->document($html);
        $sources = [];
        foreach ($document->getElementsByTagName('iframe') as $frame) {
            if ($frame->hasAttribute('srcdoc')) {
                $sources[] = $frame->getAttribute('srcdoc');
            }
        }
        // Keep inline behavior as inert source: simulator branches often exist only
        // in JavaScript, not in the initial visible welcome message.
        $result = $sources ? implode("\n", $sources) : $html;
        return $this->bounded($result);
    }

    private function document(string $content, bool $html = true): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $ok = $html
                ? $document->loadHTML('<?xml encoding="UTF-8">'.$content, LIBXML_NONET)
                : $document->loadXML($content, LIBXML_NONET);
            if (!$ok) {
                throw ValidationException::withMessages(['file' => 'The document could not be parsed.']);
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function bounded(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8') || trim($text) === '' || strlen($text) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['source' => 'Source must be nonempty UTF-8 text, at most 180 KB.']);
        }
        return $text;
    }
}
