<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;


/**
 * MediaUploadService
 *
 * Dedicated service for uploading media to Meta's Resumable Upload API.
 *
 * WHY SEPARATE?
 * Carousel templates need to upload MULTIPLE files (one per card) to get
 * "handles". These handles are used ONLY for template CREATION.
 * When you later SEND the template, Meta needs fresh "media IDs" — that is
 * a completely different upload done at send-time.
 *
 * Keeping this isolated means WhatsappService stays clean and this logic
 * can be called from anywhere (carousel create, standard create, campaigns).
 *
 * USAGE:
 *   $uploader = new MediaUploadService($accessToken, $apiVersion, $appId);
 *
 *   // Upload to Meta → get the handle back
 *   $result = $uploader->uploadToMeta($file);   // $result->handle = "4::anBl..."
 *
 *   // Optionally save a local copy for reference
 *   $localPath = $uploader->saveLocally($file, 'my_carousel_card_0');
 */
class MediaUploadService
{
    private string $accessToken;
    private string $apiVersion;
    private string $appId;

    public function __construct(string $accessToken, string $apiVersion, string $appId)
    {
        $this->accessToken = $accessToken;
        $this->apiVersion  = $apiVersion;
        $this->appId       = $appId;
    }

    /**
     * Upload a single file to Meta and return its handle.
     *
     * The returned handle is what you put inside:
     *   components[i].example.header_handle[0]   (carousel cards)
     *   components[0].example.header_handle[0]   (standard IMAGE/VIDEO header)
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return object  { success: bool, handle: string|null, error: string|null }
     */
    public function uploadToMeta($file): object
    {
        $result = new \stdClass();

        // --- Step 1: ask Meta to open an upload session ---
        $session = $this->createUploadSession($file);
        if (!$session->success) {
            $result->success = false;
            $result->handle  = null;
            $result->error   = $session->error;
            return $result;
        }

        // --- Step 2: push the file bytes into that session ---
        $upload = $this->uploadFileToSession($session->data->id, $file);
        if (!$upload->success) {
            $result->success = false;
            $result->handle  = null;
            $result->error   = $upload->error;
            return $result;
        }

        // Meta returns the handle in the "h" field
        $result->success = true;
        $result->handle  = $upload->data->h;
        $result->error   = null;
        return $result;
    }

    /**
     * Upload multiple files to Meta with bounded concurrency.
     *
     * Each file still follows Meta's two-step resumable upload flow, but the
     * files no longer wait on each other one by one.
     *
     * @param  array<int|string, \Illuminate\Http\UploadedFile>  $files
     * @return array<int|string, object>
     */
    public function uploadManyToMeta(array $files, int $concurrency = 3): array
    {
        if (empty($files)) {
            return [];
        }

        $client = new Client([
            'connect_timeout' => 15,
            'timeout' => 120,
        ]);

        $results = [];
        $concurrency = max(1, min($concurrency, count($files), 5));

        $requests = function () use ($files, $client) {
            foreach ($files as $index => $file) {
                yield $index => function () use ($client, $file) {
                    return $this->createUploadSessionAsync($client, $file)
                        ->then(function ($session) use ($client, $file) {
                            $sessionId = $session->id ?? null;

                            if (!$sessionId) {
                                throw new \RuntimeException('Meta upload session did not return an id.');
                            }

                            return $this->uploadFileToSessionAsync($client, $sessionId, $file);
                        })
                        ->then(function ($upload) {
                            $handle = $upload->h ?? null;

                            if (!$handle) {
                                throw new \RuntimeException('Meta upload did not return a media handle.');
                            }

                            $result = new \stdClass();
                            $result->success = true;
                            $result->handle = $handle;
                            $result->error = null;

                            return $result;
                        })
                        ->otherwise(function ($reason) {
                            $result = new \stdClass();
                            $result->success = false;
                            $result->handle = null;
                            $result->error = $this->extractErrorFromReason($reason);

                            return $result;
                        });
                };
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($result, $index) use (&$results) {
                $results[$index] = $result;
            },
            'rejected' => function ($reason, $index) use (&$results) {
                $result = new \stdClass();
                $result->success = false;
                $result->handle = null;
                $result->error = $this->extractErrorFromReason($reason);

                $results[$index] = $result;
            },
        ]);

        $pool->promise()->wait();
        ksort($results);

        return $results;
    }

    /**
     * Save an uploaded file to local disk under storage/app/carousel_media/{identifier}/
     *
     * We do this so we have a local reference even if Meta's CDN links expire.
     * The local path gets stored alongside the handle in the template metadata JSON.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @param  string                         $identifier   e.g. "my_carousel_card_0"
     * @return string|null   Relative path inside storage/app/, or null on failure
     */
    public function saveLocally(UploadedFile $file, string $identifier): ?array
    {
        try {
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $extension = $file->getClientOriginalExtension();
            $safeBaseName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName ?: 'media');
            $safeIdentifier = preg_replace('/[^A-Za-z0-9._-]/', '_', $identifier ?: 'carousel_card');
            $fileName = $safeIdentifier . '_' . uniqid() . '_' . $safeBaseName;

            if ($extension) {
                $fileName .= '.' . strtolower($extension);
            }

            // Save directly into storage/app/public
            Storage::disk('local')->put(
                'public/' . $fileName,
                file_get_contents($file->getPathname())
            );

            // Public URL (same pattern as your working code)
            $mediaUrl = rtrim(config('app.url'), '/')
                . '/media/public/'
                . $fileName;

            return [
                'path' => 'public/' . $fileName,
                'url'  => $mediaUrl,
            ];
        } catch (\Exception $e) {
            Log::error('MediaUploadService::saveLocally', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // PRIVATE — Meta Resumable Upload two-step protocol
    // ────────────────────────────────────────────────────────────────────

    /**
     * POST {appId}/uploads  →  Meta opens a session and returns { id }
     */
    private function createUploadSession($file): object
    {
        $url    = "https://graph.facebook.com/{$this->apiVersion}/{$this->appId}/uploads";
        $client = new Client();
        $result = new \stdClass();

        try {
            $response = $client->request('POST', $url, [
                'form_params' => [
                    'file_length'  => $file->getSize(),
                    'file_type'    => $file->getMimeType(),
                    'access_token' => $this->accessToken,
                ],
            ]);
            $result->success = true;
            $result->data    = json_decode($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            $result->success = false;
            $result->error   = $this->extractError($e);
        } catch (\Exception $e) {
            $result->success = false;
            $result->error   = $e->getMessage();
        }

        return $result;
    }

    private function createUploadSessionAsync(Client $client, $file)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->appId}/uploads";

        return $client->requestAsync('POST', $url, [
            'form_params' => [
                'file_length' => $file->getSize(),
                'file_type' => $file->getMimeType(),
                'access_token' => $this->accessToken,
            ],
        ])->then(function (ResponseInterface $response) {
            return json_decode($response->getBody()->getContents());
        });
    }

    /**
     * POST {sessionId}  with raw file bytes  →  Meta returns { h: "<handle>" }
     */
    private function uploadFileToSession(string $sessionId, $file): object
    {
        $url    = "https://graph.facebook.com/{$this->apiVersion}/{$sessionId}";
        $client = new Client();
        $result = new \stdClass();
        $stream = null;

        try {
            $stream   = fopen($file->getPathname(), 'r');
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => "OAuth {$this->accessToken}",
                    'file_offset'   => '0',
                ],
                'body'    => $stream,
                'timeout' => 60,
            ]);

            $result->success = true;
            $result->data    = json_decode($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            $result->success = false;
            $result->error   = $this->extractError($e);
        } catch (\Exception $e) {
            $result->success = false;
            $result->error   = $e->getMessage();
        } finally {
            if (is_resource($stream)) fclose($stream);
        }

        return $result;
    }

    private function uploadFileToSessionAsync(Client $client, string $sessionId, $file)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$sessionId}";
        $stream = fopen($file->getPathname(), 'r');

        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to open media file for upload.');
        }

        return $client->requestAsync('POST', $url, [
            'headers' => [
                'Authorization' => "OAuth {$this->accessToken}",
                'file_offset' => '0',
            ],
            'body' => $stream,
            'timeout' => 120,
        ])->then(
            function (ResponseInterface $response) use ($stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                return json_decode($response->getBody()->getContents());
            },
            function ($reason) use ($stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($reason instanceof \Throwable) {
                    throw $reason;
                }

                throw new \RuntimeException((string) $reason);
            }
        );
    }

    /** Pull the friendliest error string out of a Guzzle exception */
    private function extractError(GuzzleException $e): string
    {
        try {
            if (!method_exists($e, 'getResponse') || !$e->getResponse()) {
                return $e->getMessage();
            }

            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            return $body['error']['error_user_msg']
                ?? $body['error']['message']
                ?? $e->getMessage();
        } catch (\Throwable $ex) {
            return $e->getMessage();
        }
    }

    private function extractErrorFromReason($reason): string
    {
        if ($reason instanceof GuzzleException) {
            return $this->extractError($reason);
        }

        if ($reason instanceof \Throwable) {
            return $reason->getMessage();
        }

        return is_string($reason) ? $reason : 'Meta media upload failed.';
    }
}
