<?php

namespace Modules\FlowBuilder\Validators;

use Illuminate\Support\Facades\Validator;

class FlowValidator
{
    /**
     * Validate WhatsApp message nodes for required fields.
     *
     * @param array $nodes
     * @return array|bool
     */
    public function validateMessageNodes(array $nodes)
    {
        $errors = [];

        foreach ($nodes['nodes'] as $node) {
            $data = $node['data']['metadata']['fields'] ?? [];

            // Validate based on node type
            switch ($node['type']) {
                case 'start':
                    // Start node validation — triggers are now managed via TriggerGroups
                    break;

                case 'text':
                    $textValidation = $this->validateText($data);
                    if ($textValidation !== true) {
                        $errors['text'] = $textValidation;
                    }
                    break;

                case 'media':
                    $mediaValidation = $this->validateMedia($data);
                    if ($mediaValidation !== true) {
                        $errors['media'] = $mediaValidation;
                    }
                    break;

                case 'buttons':
                    $buttonsValidation = $this->validateButtons($data);
                    if ($buttonsValidation !== true) {
                        $errors['buttons'] = $buttonsValidation;
                    }
                    break;

                case 'list':
                    $listValidation = $this->validateList($data);
                    if ($listValidation !== true) {
                        $errors['list'] = $listValidation;
                    }
                    break;

                case 'question':
                    $questionValidation = $this->validateQuestion($data);
                    if ($questionValidation !== true) {
                        $errors['question'] = $questionValidation;
                    }
                    break;

                case 'action':
                    $actionValidation = $this->validateAction($node['data']);
                    if ($actionValidation !== true) {
                        $errors['action'] = $actionValidation;
                    }
                    break;

                default:
                    $errors['unknown'] = 'Unknown node type: ' . $node['type'];
                    break;
            }
        }

        // Return true if no errors found, otherwise return errors
        return empty($errors) ? true : $errors;
    }

    /**
     * Validate a text message node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateText(array $data)
    {
        $errors = [];

        if (empty($data['body'])) {
            $errors[] = 'Text body is required.';
        }

        if (!empty($data['body']) && mb_strlen($data['body'], 'UTF-8') > 4096) {
            $errors[] = 'Text body must not exceed 4096 characters.';
        }

        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate a media message node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateMedia(array $data)
    {
        $errors = [];

        if (empty($data['media']) || empty($data['media']['path'])) {
            $errors[] = 'Media path is required.';
        }

        if (empty($data['mediaType'])) {
            $errors[] = 'Media type (image, audio, etc.) is required.';
        }

        // Validate mediaType format based on mimeType
        if (!empty($data['mediaType'])) {
            if(isset($data['media']['metadata'])){
                $mimeType = json_decode($data['media']['metadata'], true)['extension'] ?? '';
            } else {
                $mimeType = '';
            }
            $validTypes = $this->validateMediaTypeFormat($mimeType, $data['mediaType']);
            if ($validTypes !== true) {
                $errors[] = $validTypes;
            }
        }

        // Caption limit: 1024 for image/video/document, 0 for audio
        if (!empty($data['caption'])) {
            if ($data['mediaType'] === 'audio') {
                $errors[] = 'Audio messages cannot have a caption.';
            } elseif (mb_strlen($data['caption'], 'UTF-8') > 1024) {
                $errors[] = 'Caption must not exceed 1024 characters.';
            }
        }

        // Media file size limits
        if (!empty($data['media']) && !empty($data['media']['metadata'])) {
            $mediaMeta = is_string($data['media']['metadata'])
                ? json_decode($data['media']['metadata'], true)
                : $data['media']['metadata'];
            $fileSize = $mediaMeta['size'] ?? 0;
            $maxSizes = [
                'image' => 5 * 1024 * 1024,    // 5 MB
                'video' => 16 * 1024 * 1024,    // 16 MB
                'audio' => 16 * 1024 * 1024,    // 16 MB
                'document' => 25 * 1024 * 1024,  // 25 MB
            ];
            $mediaType = $data['mediaType'] ?? '';
            if (isset($maxSizes[$mediaType]) && $fileSize > $maxSizes[$mediaType]) {
                $mbLimit = $maxSizes[$mediaType] / (1024 * 1024);
                $errors[] = ucfirst($mediaType) . " file must not exceed {$mbLimit}MB.";
            }
        }

        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate buttons message node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateButtons(array $data)
    {
        $errors = [];

        // Validate 'headerType' and corresponding fields
        if (isset($data['headerType'])) {
            switch ($data['headerType']) {
                case 'text':
                    if (empty($data['headerText'])) {
                        $errors[] = 'Header text is required when headerType is "text".';
                    }
                    if (!empty($data['headerText']) && mb_strlen($data['headerText'], 'UTF-8') > 60) {
                        $errors[] = 'Header text must not exceed 60 characters.';
                    }
                    break;

                case 'image':
                case 'video':
                case 'document':
                    if (empty($data['headerMedia'])) {
                        $errors[] = 'Media is required when headerType is "' . $data['headerType'] . '".';
                    } else if (!empty($data['headerMedia'])) {
                        $mimeType = json_decode($data['headerMedia']['metadata'], true)['extension'] ?? '';
                        $validTypes = $this->validateMediaTypeFormat($mimeType, $data['headerType']);
                        if ($validTypes !== true) {
                            $errors[] = $validTypes;
                        }
                        // Header media file size limits
                        if (!empty($data['headerMedia']['metadata'])) {
                            $mediaMeta = is_string($data['headerMedia']['metadata'])
                                ? json_decode($data['headerMedia']['metadata'], true)
                                : $data['headerMedia']['metadata'];
                            $fileSize = $mediaMeta['size'] ?? 0;
                            $maxSizes = [
                                'image' => 5 * 1024 * 1024,
                                'video' => 16 * 1024 * 1024,
                                'document' => 25 * 1024 * 1024,
                            ];
                            if (isset($maxSizes[$data['headerType']]) && $fileSize > $maxSizes[$data['headerType']]) {
                                $mbLimit = $maxSizes[$data['headerType']] / (1024 * 1024);
                                $errors[] = ucfirst($data['headerType']) . " file must not exceed {$mbLimit}MB.";
                            }
                        }
                    }
                    break;
            }
        }

        // Check if 'body' has a value
        if (empty($data['body'])) {
            $errors[] = 'Body text is required.';
        }
        if (!empty($data['body']) && mb_strlen($data['body'], 'UTF-8') > 1024) {
            $errors[] = 'Body text must not exceed 1024 characters.';
        }

        // Footer limit
        if (!empty($data['footer']) && mb_strlen($data['footer'], 'UTF-8') > 60) {
            $errors[] = 'Footer text must not exceed 60 characters.';
        }

        // Check if 'buttonType' exists and is 'buttons'
        if (($data['buttonType'] ?? '') === 'buttons' && isset($data['buttons'])) {
            $validButtons = array_filter([
                'button1' => $data['buttons']['button1'] ?? '',
                'button2' => $data['buttons']['button2'] ?? '',
                'button3' => $data['buttons']['button3'] ?? '',
            ]);

            if (empty($validButtons)) {
                $errors[] = 'At least one button (button1, button2, button3) must have a value.';
            }

            foreach ($validButtons as $key => $value) {
                if (!$this->validateButtonValueLength($value)) {
                    $errors[] = ucfirst($key) . ' must be between 1 to 20 characters.';
                }
            }
        }

        // Check if 'buttonType' exists and is 'cta_url'
        if (isset($data['buttonType']) && $data['buttonType'] === 'cta_url') {
            if (empty($data['ctaUrlButton']) || !isset($data['ctaUrlButton']['displayText']) || !isset($data['ctaUrlButton']['url'])) {
                $errors[] = 'Both displayText and url are required for ctaUrlButton.';
            }

            if (empty($data['ctaUrlButton']['displayText']) || empty($data['ctaUrlButton']['url'])) {
                $errors[] = 'Both displayText and url must not be empty.';
            }

            if (!empty($data['ctaUrlButton']['displayText']) && mb_strlen($data['ctaUrlButton']['displayText'], 'UTF-8') > 25) {
                $errors[] = 'CTA display text must not exceed 25 characters.';
            }

            $urlPattern = '/^(https?:\/\/)([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?(\/\S*)?$/';

            if (!preg_match($urlPattern, $data['ctaUrlButton']['url'])) {
                $errors[] = 'The URL format is invalid. Please provide a valid URL.';
            }
        }

        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate a question node.
     */
    private function validateQuestion(array $data)
    {
        $errors = [];

        if (empty($data['question_text'])) {
            $errors[] = 'Question text is required.';
        }
        if (!empty($data['question_text']) && mb_strlen($data['question_text'], 'UTF-8') > 1024) {
            $errors[] = 'Question text must not exceed 1024 characters.';
        }

        if (empty($data['variable_name'])) {
            $errors[] = 'Variable name is required.';
        }
        if (!empty($data['variable_name']) && mb_strlen($data['variable_name'], 'UTF-8') > 50) {
            $errors[] = 'Variable name must not exceed 50 characters.';
        }

        if (!empty($data['retry_message']) && mb_strlen($data['retry_message'], 'UTF-8') > 1024) {
            $errors[] = 'Retry message must not exceed 1024 characters.';
        }

        if (isset($data['max_retries']) && ($data['max_retries'] < 1 || $data['max_retries'] > 10)) {
            $errors[] = 'Max retries must be between 1 and 10.';
        }

        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate action node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateAction(array $data)
    {
        $errors = [];

        $actionType = $data['actionType'] ?? '';
        $config = $data['config'] ?? [];
        $isActive = $data['is_active'] ?? true;

        // If action is not active, skip validation
        if (!$isActive) {
            return true;
        }

        // Normalize action type: convert hyphens to underscores
        $actionType = str_replace('-', '_', $actionType);

        // Validate based on action type
        switch ($actionType) {
            case 'add_to_group':
            case 'remove_from_group':
                if (empty($config['group_id'])) {
                    $errors[] = 'Group ID is required for ' . $actionType . ' action.';
                }
                break;

            case 'update_contact':
                if (empty($config['target_field'])) {
                    $errors[] = 'Target field is required for update_contact action.';
                }
                break;

            case 'send_email':
                if (empty($config['subject'])) {
                    $errors[] = 'Subject is required for send_email action.';
                }
                if (empty($config['body'])) {
                    $errors[] = 'Body is required for send_email action.';
                }
                if (empty($config['smtp_host'])) {
                    $errors[] = 'SMTP host is required for send_email action.';
                }
                if (empty($config['smtp_username'])) {
                    $errors[] = 'SMTP username is required for send_email action.';
                }
                if (empty($config['smtp_password'])) {
                    $errors[] = 'SMTP password is required for send_email action.';
                }
                break;

            case 'delay':
                if (!isset($config['duration']) || $config['duration'] <= 0) {
                    $errors[] = 'Duration must be greater than 0 for delay action.';
                }
                break;

            case 'webhook':
                if (empty($config['url'])) {
                    $errors[] = 'URL is required for webhook action.';
                }
                break;

            case 'ai_response':
                if (empty($config['prompt'])) {
                    $errors[] = 'Prompt is required for ai_response action.';
                }
                break;

            case 'conditional':
                if (empty($config['condition_type'])) {
                    $errors[] = 'Condition type is required for conditional action.';
                }
                if (empty($config['conditions']) || !is_array($config['conditions'])) {
                    $errors[] = 'Conditions array is required for conditional action.';
                }
                if ($config['condition_type'] === 'contact_field' && empty($config['field_name'])) {
                    $errors[] = 'Field name is required for contact_field condition type.';
                }
                break;
            
            case 'assign_to_agent':
                if (empty($config['agent_id'])) {
                    $errors[] = 'Agent ID is required for assign_to_agent action.';
                }
                break;

            case 'label':
                if (!in_array($config['action'] ?? '', ['add', 'remove'])) {
                    $errors[] = 'Action must be "add" or "remove" for label action.';
                }
                if (empty($config['label_ids']) || !is_array($config['label_ids'])) {
                    $errors[] = 'At least one label is required for label action.';
                }
                break;

            default:
                if (empty($actionType)) {
                    $errors[] = 'Action type is required.';
                } else {
                    $errors[] = 'Unknown action type: ' . $actionType;
                }
                break;
        }

        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate list message node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateList(array $data)
    {
        $errors = [];

        // Validate 'headerType' and corresponding fields
        if (isset($data['headerType'])) {
            switch ($data['headerType']) {
                case 'text':
                    if (empty($data['headerText'])) {
                        $errors[] = 'Header text is required when headerType is "text".';
                    }
                    if (!empty($data['headerText']) && mb_strlen($data['headerText'], 'UTF-8') > 60) {
                        $errors[] = 'Header text must not exceed 60 characters.';
                    }
                    break;

                case 'image':
                case 'video':
                case 'document':
                    if (empty($data['headerMedia'])) {
                        $errors[] = 'Media is required when headerType is "' . $data['headerType'] . '".';
                    } else if (!empty($data['headerMedia'])) {
                        $mimeType = json_decode($data['headerMedia']['metadata'], true)['extension'] ?? '';
                        $validTypes = $this->validateMediaTypeFormat($mimeType, $data['headerType']);
                        if ($validTypes !== true) {
                            $errors[] = $validTypes;
                        }
                        // Header media file size limits
                        if (!empty($data['headerMedia']['metadata'])) {
                            $mediaMeta = is_string($data['headerMedia']['metadata'])
                                ? json_decode($data['headerMedia']['metadata'], true)
                                : $data['headerMedia']['metadata'];
                            $fileSize = $mediaMeta['size'] ?? 0;
                            $maxSizes = [
                                'image' => 5 * 1024 * 1024,
                                'video' => 16 * 1024 * 1024,
                                'document' => 25 * 1024 * 1024,
                            ];
                            if (isset($maxSizes[$data['headerType']]) && $fileSize > $maxSizes[$data['headerType']]) {
                                $mbLimit = $maxSizes[$data['headerType']] / (1024 * 1024);
                                $errors[] = ucfirst($data['headerType']) . " file must not exceed {$mbLimit}MB.";
                            }
                        }
                    }
                    break;
            }
        }

        // Check if 'body' has a value
        if (empty($data['body'])) {
            $errors[] = 'Body text is required.';
        }
        if (!empty($data['body']) && mb_strlen($data['body'], 'UTF-8') > 1024) {
            $errors[] = 'Body text must not exceed 1024 characters.';
        }

        // Footer limit
        if (!empty($data['footer']) && mb_strlen($data['footer'], 'UTF-8') > 60) {
            $errors[] = 'Footer text must not exceed 60 characters.';
        }

        // Check if button label has a value
        if (empty($data['buttonLabel'])) {
            $errors[] = 'Button label is required.';
        }
        if (!empty($data['buttonLabel']) && mb_strlen($data['buttonLabel'], 'UTF-8') > 20) {
            $errors[] = 'Button label must not exceed 20 characters.';
        }

        // Check if list sections are provided
        if (empty($data['sections']) || !is_array($data['sections'])) {
            $errors[] = 'List sections are required.';
        }

        $hasEmptySections = false;

        if(isset($data['sections'])){
            foreach ($data['sections'] as $section) {
                if (empty($section['title'])) {
                    $errors[] = 'Each list section must have a title.';
                }
                if (!empty($section['title']) && mb_strlen($section['title'], 'UTF-8') > 24) {
                    $errors[] = 'Section title must not exceed 24 characters.';
                }

                if (empty($section['rows']) || !is_array($section['rows'])) {
                    $errors[] = 'Each list section must have rows.';
                }

                $allRowsEmpty = true;
                foreach ($section['rows'] as $row) {
                    if (!empty($row['title']) && !empty($row['id'])) {
                        $allRowsEmpty = false;
                    }
                    if (!empty($row['title']) && mb_strlen($row['title'], 'UTF-8') > 24) {
                        $errors[] = 'Row title must not exceed 24 characters.';
                    }
                    if (!empty($row['id']) && mb_strlen($row['id'], 'UTF-8') > 200) {
                        $errors[] = 'Row ID must not exceed 200 characters.';
                    }
                    if (!empty($row['description']) && mb_strlen($row['description'], 'UTF-8') > 72) {
                        $errors[] = 'Row description must not exceed 72 characters.';
                    }
                }

                if ($allRowsEmpty) {
                    $hasEmptySections = true;
                }
            }
        }

        if ($hasEmptySections) {
            $errors[] = 'Each section must contain at least one row with both a title and an id.';
        }

        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate the format of the media type based on its MIME type.
     * 
     * @param string $mimeType
     * @param string $mediaType
     * @return bool|string
     */
    private function validateMediaTypeFormat($mimeType, $mediaType)
    {
        // Define valid formats for each category
        $validFormats = [
            'image' => ['image/jpeg', 'image/png'],
            'audio' => ['audio/mpeg', 'audio/mp3', 'audio/aac', 'audio/amr', 'audio/mp4', 'audio/ogg'],
            'document' => [
                'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            ],
            'video' => ['video/mp4', 'video/3gpp']
        ];

        // Check if media type matches the predefined categories and mimeType
        if ($mediaType === 'image') {
            // Only allow 'image/jpeg' or 'image/png' for image mediaType
            if (!in_array($mimeType, $validFormats['image'])) {
                return 'Invalid image format. Allowed types: JPEG, PNG.';
            }
        }

        if ($mediaType === 'audio') {
            // Only allow valid audio formats
            if (!in_array($mimeType, $validFormats['audio'])) {
                return 'Invalid audio format. Allowed types: MP3, AAC, AMR, MP4, OGG.';
            }
        }

        if ($mediaType === 'document') {
            // Only allow valid document formats
            if (!in_array($mimeType, $validFormats['document'])) {
                return 'Invalid document format. Allowed types: PDF, TXT, DOC, DOCX, XLS, XLSX, PPT, PPTX.';
            }
        }

        if ($mediaType === 'video') {
            // Only allow valid video formats
            if (!in_array($mimeType, $validFormats['video'])) {
                return 'Invalid video format. Allowed types: MP4, 3GPP.';
            }
        }

        return true; // Return true if no validation errors found
    }

    /**
     * Validate button value length (1-20 characters).
     *
     * @param string $value
     * @return bool
     */
    private function validateButtonValueLength($value): bool
    {
        $length = mb_strlen($value, 'UTF-8');
        return $length >= 1 && $length <= 20;
    }
}
