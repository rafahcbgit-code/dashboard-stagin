<?php

namespace Modules\FlowBuilder\Services;

use App\Models\Setting;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowMedia;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Resources\FlowResource;
use Modules\FlowBuilder\Validators\FlowValidator;

class FlowService
{
    public function getRows(object $request)
    {
        $organizationId = session()->get('current_organization');
        $model = new Flow;
        $searchTerm = $request->query('search');

        return FlowResource::collection($model->listAll($organizationId, $searchTerm));
    }

    /**
     * Create a new flow.
     *
     * @param array $data
     * @return Flow
     */
    public function createFlow(array $data): Flow
    {
        $organizationId = session()->get('current_organization');

        return Flow::create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'description' => $data['description'],
            'status' => 'inactive',
        ]);
    }

    /**
     * Update an existing flow.
     *
     * @param string $uuid
     * @param array $data
     * @return Flow
     */
    public function updateFlow($uuid, array $data, $publish)
    {
        $validator = new FlowValidator();
        
        $flow = Flow::where('uuid', $uuid)->firstOrFail();

        $incomingMetadata = json_decode($data['metadata'] ?? $flow->metadata, true);
        $ctaDisplayTextValidation = $validator->validateCtaDisplayTextLengths($incomingMetadata ?: []);

        if (is_array($ctaDisplayTextValidation)) {
            return response()->json([
                'success' => false,
                'errors' => $ctaDisplayTextValidation,
                'status' => $flow->status,
            ], 422);
        }

        if(isset($flow->metadata)){
            $metadataArray = json_decode($flow->metadata, true);
            $result = $validator->validateMessageNodes($metadataArray);

            if(is_array($result)){
                $data['status'] = 'inactive';
            }
        }

        $flow->update($data);

        $flow = Flow::where('uuid', $uuid)->firstOrFail();

        if(isset($flow->metadata)){
            $metadataArray = json_decode($flow->metadata, true);
            $result = $validator->validateMessageNodes($metadataArray);

            if(is_array($result)){
                $flow->update(['status' => 'inactive']);
            }
        }

        if(isset($publish)){
            $validator = new FlowValidator();
            $metadataArray = json_decode($flow->metadata, true);
            $status = $publish == 1 ? 'active' : 'inactive';

            if($publish == 1){
                $result = $validator->validateMessageNodes($metadataArray);

                if (!is_array($result)) {
                    $flow->update(['status' => $status]);

                    return response()->json([
                        'success' => true,
                        'message' => __('Flow saved & published successfully!'),
                        'status' => 'active'
                    ]);
                } else {
                    // Return the errors in JSON format with a 422 Unprocessable Entity status code
                    return response()->json([
                        'success' => false,
                        'errors' => $result,
                        'status' => 'inactive'
                    ], 200);
                }
            } else {
                $flow->update(['status' => $status]);

                return response()->json([
                    'success' => true,
                    'message' => __('Flow saved & unpublished successfully!'),
                    'status' => 'inactive'
                ], 200);
            }
        }

        return response()->json([
            'success' => true,
            'message' => __('Flow saved successfully!'),
            'status' => $flow->status,
        ], 200);

        return $flow;
    }

    public function duplicateFlow($uuid): void
    {
        $flow = Flow::where('uuid', $uuid)->first();

        if (!$flow) {
            return;
        }

        // Get the base name without any existing (number) suffix
        $baseName = preg_replace('/\(\d+\)$/', '', trim($flow->name));

        // Find existing duplicates
        $count = Flow::where('name', 'LIKE', "{$baseName} (%)")
            ->orWhere('name', $baseName)
            ->count();

        // Set the new name with an incremented number
        $newName = $count ? "{$baseName} ({$count})" : "{$baseName} (1)";

        // Duplicate the flow and assign the new name
        $duplicate = $flow->replicate(['uuid']);
        $duplicate->name = $newName;
        $duplicate->save();

        // Duplicate FlowMedia records for the new flow
        $oldFlowId = $flow->id;
        $newFlowId = $duplicate->id;
        $mediaIdMap = []; // old media id => new FlowMedia model

        $originalMediaRows = FlowMedia::where('flow_id', $oldFlowId)->get();
        foreach ($originalMediaRows as $originalMedia) {
            $newMedia = $originalMedia->replicate(['uuid']);
            $newMedia->flow_id = $newFlowId;
            $newMedia->save();
            $mediaIdMap[$originalMedia->id] = $newMedia;
        }

        // Update flow_id references in the duplicated metadata JSON
        if (!empty($mediaIdMap)) {
            $metadata = json_decode($duplicate->metadata, true);
            if ($metadata && isset($metadata['nodes'])) {
                foreach ($metadata['nodes'] as &$node) {
                    $mediaRef = $node['data']['metadata']['fields']['media'] ?? null;
                    if (is_array($mediaRef) && isset($mediaRef['flow_id']) && $mediaRef['flow_id'] == $oldFlowId) {
                        $mediaRef['flow_id'] = $newFlowId;
                        // Update the media id to point to the new FlowMedia record
                        if (isset($mediaRef['id']) && isset($mediaIdMap[$mediaRef['id']])) {
                            $newMediaModel = $mediaIdMap[$mediaRef['id']];
                            $mediaRef['id'] = $newMediaModel->id;
                            $mediaRef['uuid'] = $newMediaModel->uuid;
                        }
                        $node['data']['metadata']['fields']['media'] = $mediaRef;
                    }
                }
                unset($node);
                $duplicate->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
                $duplicate->save();
            }
        }
    }

    public function uploadMedia($request, $uuid, $stepId)
    {
        $flow = Flow::where('uuid', $uuid)->first();
        $fileName = $request->file('file')->getClientOriginalName();
        $fileContent = $request->file('file');
        $storage = Setting::where('key', 'storage_system')->first()->value;

        // Get file extension or MIME type
        $fileExtension = strtolower($fileContent->getClientOriginalExtension());
        $fileMimeType = strtolower($fileContent->getMimeType());
        $fileSize = $fileContent->getSize();

        // Validate file size by media type
        $maxSizes = [
            'image' => 5 * 1024 * 1024,    // 5 MB
            'video' => 16 * 1024 * 1024,    // 16 MB
            'audio' => 16 * 1024 * 1024,    // 16 MB
            'document' => 25 * 1024 * 1024,  // 25 MB
        ];

        // Determine media type from MIME type
        $mediaType = null;
        if (str_starts_with($fileMimeType, 'image/')) {
            $mediaType = 'image';
        } elseif (str_starts_with($fileMimeType, 'video/')) {
            $mediaType = 'video';
        } elseif (str_starts_with($fileMimeType, 'audio/')) {
            $mediaType = 'audio';
        } else {
            $mediaType = 'document';
        }

        if ($mediaType && isset($maxSizes[$mediaType]) && $fileSize > $maxSizes[$mediaType]) {
            $mbLimit = $maxSizes[$mediaType] / (1024 * 1024);
            abort(422, ucfirst($mediaType) . " file must not exceed {$mbLimit}MB.");
        }

        if($storage === 'local'){
            $location = 'local';
            $file = \Storage::disk('local')->put('public', $fileContent);
            $mediaFilePath = $file;
            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
        } else if($storage === 'aws') {
            $organizationId = session()->get('current_organization');
            $location = 'amazon';
            $file = $request->file('file');
            $filePath = 'uploads/media/received/'  . $organizationId . '/' . $fileName;
            $uploadedFile = $file->store('uploads/media/sent/' . $organizationId, 's3');
            $mediaFilePath = \Storage::disk('s3')->url($uploadedFile);
            $mediaUrl = $mediaFilePath;
        }

        $mediaData = [
            'path' => $mediaUrl,
            'location' => $storage,
            'metadata' => json_encode([
                'name' => $fileName,
                'extension' => $fileMimeType,
                'size' => $fileContent->getSize()
            ])
        ];

        // Find or create: update existing media for this node, or create a new record
        $flowMedia = FlowMedia::where('flow_id', $flow->id)
            ->where('step_id', $stepId)
            ->first();

        if ($flowMedia) {
            $flowMedia->update($mediaData);
        } else {
            $flowMedia = FlowMedia::create(array_merge($mediaData, [
                'flow_id' => $flow->id,
                'step_id' => $stepId,
            ]));
        }

        return $flowMedia;
    }

    /**
     * Delete a flow and its related steps.
     *
     * @param Flow $flow
     * @return void
     */
    public function deleteFlow($uuid): void
    {
        $flow = Flow::where('uuid', $uuid)->first();

        $flow->delete(); // Cascade will delete related steps
    }

    public function handleUserReply($contactId)
    {
        // Get user progress or start at step 1
        $userProgress = FlowUserData::firstOrCreate(
            ['contact_id' => $contactId],
            ['current_step' => 1]
        );

        $currentStep = 1;

        $flow = Flow::where('flow_id', $userProgress->flow_id)->first();

        $nextStep = $this->getNextStep($currentStep, $flow->metadata);

        if ($nextStep) {
            // Send message for the current step
            $this->sendMessage($userId, $nextStep);
    
            // Update user progress
            $userProgress->incrementStep();
        }
    }

    private function checkSteps($metadata){

    }

    function getNextStep($currentNodeId, $metadata)
    {
        $flowMetadata = json_decode($metadata, true);
        $nodes = $flowMetadata['nodes'];
        $edges = $flowMetadata['edges'];

        // Find the edge where the source is the current node
        foreach ($edges as $edge) {
            if ($edge['source'] === $currentNodeId) {
                $nextNodeId = $edge['target'];

                // Find and return the next node details
                foreach ($nodes as $node) {
                    if ($node['id'] === $nextNodeId) {
                        return $node;
                    }
                }
            }
        }

        // Return null if there are no more steps
        return null;
    }

    /**
     * Get simplified flow metadata with only essential data
     */
    public function getSimplifiedFlow($flowMetadata)
    {
        if (is_string($flowMetadata)) {
            $flowMetadata = json_decode($flowMetadata, true);
        }
        
        $simplified = [
            'nodes' => [],
            'edges' => []
        ];
        
        // Simplify nodes
        foreach ($flowMetadata['nodes'] ?? [] as $node) {
            $simpleNode = [
                'id' => $node['id'] ?? null,
                'type' => $node['type'] ?? null,
            ];
            
            // Add essential data based on node type
            if (isset($node['data'])) {
                $simpleNode['data'] = [];
                
                // For start nodes — just pass through, no trigger/keywords
                if ($node['type'] === 'start') {
                    $simpleNode['data']['metadata'] = [
                        'fields' => $node['data']['metadata']['fields'] ?? []
                    ];
                }
                // For message nodes (text, buttons, etc.)
                elseif (in_array($node['type'], ['text', 'buttons'])) {
                    $fields = $node['data']['metadata']['fields'] ?? [];
                    $simpleNode['data']['metadata'] = [
                        'fields' => [
                            'type' => $fields['type'] ?? null,
                            'body' => $fields['body'] ?? null,
                            'buttonType' => $fields['buttonType'] ?? null,
                            'buttons' => $fields['buttons'] ?? null,
                            'sections' => $fields['sections'] ?? null,
                            'headerType' => $fields['headerType'] ?? null,
                            'headerText' => $fields['headerText'] ?? null,
                            'footer' => $fields['footer'] ?? null,
                            'mediaType' => $fields['mediaType'] ?? null,
                            'media' => $fields['media'] ?? null,
                            'caption' => $fields['caption'] ?? null
                        ]
                    ];
                }
                // For action nodes
                elseif ($node['type'] === 'action') {
                    $simpleNode['data'] = [
                        'actionType' => $node['data']['actionType'] ?? null,
                        'config' => $node['data']['config'] ?? null,
                        'is_active' => $node['data']['is_active'] ?? true
                    ];
                }
            }
            
            $simplified['nodes'][] = $simpleNode;
        }
        
        // Simplify edges
        foreach ($flowMetadata['edges'] ?? [] as $edge) {
            $simpleEdge = [
                'source' => $edge['source'] ?? null,
                'target' => $edge['target'] ?? null,
                'sourceHandle' => $edge['sourceHandle'] ?? null
            ];
            
            // Add minimal sourceNode info for message derivation
            if (isset($edge['sourceNode'])) {
                $simpleEdge['sourceNode'] = [
                    'id' => $edge['sourceNode']['id'] ?? null,
                    'type' => $edge['sourceNode']['type'] ?? null,
                    'data' => [
                        'metadata' => [
                            'fields' => [
                                'type' => $edge['sourceNode']['data']['metadata']['fields']['type'] ?? null,
                                'buttons' => $edge['sourceNode']['data']['metadata']['fields']['buttons'] ?? null,
                                'sections' => $edge['sourceNode']['data']['metadata']['fields']['sections'] ?? null
                            ]
                        ]
                    ]
                ];
            }
            
            // Add minimal targetNode info
            if (isset($edge['targetNode'])) {
                $simpleEdge['targetNode'] = [
                    'id' => $edge['targetNode']['id'] ?? null,
                    'type' => $edge['targetNode']['type'] ?? null
                ];
            }
            
            $simplified['edges'][] = $simpleEdge;
        }
        
        return $simplified;
    }

    /**
     * Get even more compact version - just structure overview
     */
    public function getFlowOverview($flowMetadata)
    {
        if (is_string($flowMetadata)) {
            $flowMetadata = json_decode($flowMetadata, true);
        }
        
        $overview = [
            'node_count' => count($flowMetadata['nodes'] ?? []),
            'edge_count' => count($flowMetadata['edges'] ?? []),
            'nodes_by_type' => [],
            'structure' => []
        ];
        
        // Count nodes by type
        foreach ($flowMetadata['nodes'] ?? [] as $node) {
            $type = $node['type'] ?? 'unknown';
            $overview['nodes_by_type'][$type] = ($overview['nodes_by_type'][$type] ?? 0) + 1;
            
            // Add to structure
            $overview['structure'][$node['id']] = [
                'type' => $type,
                'outgoing_edges' => 0
            ];
        }
        
        // Count outgoing edges
        foreach ($flowMetadata['edges'] ?? [] as $edge) {
            $source = $edge['source'] ?? null;
            if ($source && isset($overview['structure'][$source])) {
                $overview['structure'][$source]['outgoing_edges']++;
            }
        }
        
        return $overview;
    }

    /**
     * Usage examples:
     */
    public function debugFlow($uuid)
    {
        $flow = Flow::where('uuid', $uuid)->first();
        if (!$flow) {
            return null;
        }
        
        $simplified = $this->getSimplifiedFlow($flow->metadata);
        $overview = $this->getFlowOverview($flow->metadata);
        
        return [
            'overview' => $overview,
            'simplified' => $simplified,
            'original_size' => strlen(json_encode($flow->metadata)),
            'simplified_size' => strlen(json_encode($simplified)),
            'reduction_percent' => round((1 - (strlen(json_encode($simplified)) / strlen(json_encode($flow->metadata)))) * 100, 2)
        ];
    }
}
