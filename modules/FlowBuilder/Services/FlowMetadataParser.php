<?php

namespace Modules\FlowBuilder\Services;

use Illuminate\Support\Arr;

/**
 * Parses flow metadata JSON into a traversable structure.
 * Provides clean methods for flow execution to consume without
 * repeated json_decode/array access scattered throughout.
 */
class FlowMetadataParser
{
    private array $nodes = [];

    private array $edges = [];

    private array $nodeMap = [];

    public function __construct(string $metadataJson)
    {
        $data = json_decode($metadataJson, true) ?? [];
        $this->nodes = $data['nodes'] ?? [];
        $this->edges = $data['edges'] ?? [];

        foreach ($this->nodes as $node) {
            if (isset($node['id'])) {
                $this->nodeMap[(string) $node['id']] = $node;
            }
        }
    }

    public function getStartNode(): ?array
    {
        foreach ($this->nodes as $node) {
            if (($node['type'] ?? null) === 'start') {
                return $node;
            }
        }

        return null;
    }

    public function getStartNodeId(): ?string
    {
        $start = $this->getStartNode();

        return $start ? (string) $start['id'] : null;
    }

    public function getNodeById(string $id): ?array
    {
        return $this->nodeMap[$id] ?? null;
    }

    public function getOutgoingEdges(string $sourceId): array
    {
        return array_values(array_filter($this->edges, function ($edge) use ($sourceId) {
            return isset($edge['source']) && (string) $edge['source'] === $sourceId;
        }));
    }

    /**
     * Get the next node after a source node, optionally matching a handle.
     * For action nodes or single-edge paths, returns the first target.
     * For interactive buttons/lists, matches the handle to the user's response.
     */
    public function getNextNode(string $sourceId, string $message = '', string $handle = null): ?array
    {
        $edges = $this->getOutgoingEdges($sourceId);

        if (empty($edges)) {
            return null;
        }

        // If a specific handle is given (e.g., conditional branching), find matching edge
        if ($handle !== null) {
            foreach ($edges as $edge) {
                if (($edge['sourceHandle'] ?? null) === $handle) {
                    return $this->resolveTargetNode($edge);
                }
            }

            return null;
        }

        $sourceNode = $this->nodeMap[$sourceId] ?? null;

        // Interactive selections must match the user's button/list choice, even
        // when the node has only one outgoing edge.
        if (trim($message) !== '' && $this->isInteractiveChoiceNode($sourceNode)) {
            $derivedHandle = $this->deriveHandle($sourceNode, $message);
            if ($derivedHandle === null) {
                return null;
            }

            foreach ($edges as $edge) {
                if (($edge['sourceHandle'] ?? null) === $derivedHandle) {
                    return $this->resolveTargetNode($edge);
                }
            }

            return null;
        }

        // Single edge — follow it directly
        if (count($edges) === 1) {
            return $this->resolveTargetNode($edges[0]);
        }

        // Multiple edges — resolve by message content (interactive buttons/lists)
        $derivedHandle = $this->deriveHandle($sourceNode, $message);

        if ($derivedHandle !== null) {
            foreach ($edges as $edge) {
                if (($edge['sourceHandle'] ?? null) === $derivedHandle) {
                    return $this->resolveTargetNode($edge);
                }
            }
        }

        // Action nodes: auto-follow the first edge
        $nodeType = $sourceNode['type'] ?? null;
        if ($nodeType === 'action') {
            return $this->resolveTargetNode($edges[0]);
        }

        return null;
    }

    /**
     * Resolve an interactive reply by its stable payload ID. The visible title
     * is only used for legacy list definitions that do not have row IDs.
     */
    public function getNextNodeForInteractiveReply(
        string $sourceId,
        string $replyId,
        string $replyTitle = ''
    ): ?array {
        $sourceNode = $this->nodeMap[$sourceId] ?? null;
        if ($sourceNode === null) {
            return null;
        }

        $replyId = trim($replyId);
        $handle = $replyId !== ''
            ? $this->deriveHandleFromChoiceId($sourceNode, $replyId)
            : null;

        // Existing flows created before list row IDs were introduced may only
        // be routable by title. Do not fall back when the node does define IDs:
        // an unknown ID must never be accepted just because its title matches.
        if ($handle === null && ! $this->hasStableChoiceIds($sourceNode)) {
            $handle = $this->deriveHandle($sourceNode, $replyTitle);
        }

        return $handle === null
            ? null
            : $this->getNextNode($sourceId, '', $handle);
    }

    /**
     * Return only stable payload IDs. A historical reply must never be routed
     * by its visible title because titles are editable and are not unique.
     */
    public function getInteractiveChoiceIds(string $sourceId): array
    {
        $sourceNode = $this->nodeMap[$sourceId] ?? null;
        if ($sourceNode === null) {
            return [];
        }

        $ids = [];
        $buttons = Arr::get($sourceNode, 'data.metadata.fields.buttons', []);
        if (is_array($buttons)) {
            foreach ($buttons as $id => $label) {
                if (trim((string) $id) !== '' && trim((string) $label) !== '') {
                    $ids[] = (string) $id;
                }
            }
        }

        $sections = Arr::get($sourceNode, 'data.metadata.fields.sections', []);
        if (is_array($sections)) {
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (trim((string) ($row['id'] ?? '')) !== '') {
                        $ids[] = (string) $row['id'];
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function getDirectPortalTargetUuid(string $sourceId, string $replyId): ?string
    {
        $nextNode = $this->getNextNodeForInteractiveReply($sourceId, $replyId);
        if (($nextNode['type'] ?? null) !== 'portal') {
            return null;
        }

        $uuid = trim((string) Arr::get($nextNode, 'data.metadata.fields.target_flow_uuid', ''));

        return $uuid !== '' ? $uuid : null;
    }

    private function isInteractiveChoiceNode(?array $sourceNode): bool
    {
        if ($sourceNode === null) {
            return false;
        }

        $buttons = Arr::get($sourceNode, 'data.metadata.fields.buttons', []);
        if (is_array($buttons)) {
            foreach ($buttons as $label) {
                if (is_string($label) && trim($label) !== '') {
                    return true;
                }
            }
        }

        $sections = Arr::get($sourceNode, 'data.metadata.fields.sections', []);
        if (is_array($sections)) {
            foreach ($sections as $section) {
                if (! empty($section['rows']) && is_array($section['rows'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Derive a sourceHandle from the user's message and the source node definition.
     */
    public function deriveHandle(?array $sourceNode, string $message): ?string
    {
        $msg = strtolower(trim($message));
        if ($msg === '' || $sourceNode === null) {
            return null;
        }

        // Interactive buttons: button1→a, button2→b, button3→c
        $buttons = Arr::get($sourceNode, 'data.metadata.fields.buttons', []);
        if (! empty($buttons) && is_array($buttons)) {
            $map = ['button1' => 'a', 'button2' => 'b', 'button3' => 'c'];
            foreach ($buttons as $key => $label) {
                if (! is_string($label)) {
                    continue;
                }
                if (strtolower(trim($label)) === $msg || strtolower(trim($key)) === $msg) {
                    return $map[$key] ?? null;
                }
            }
            if (in_array($msg, ['a', 'b', 'c'])) {
                return $msg;
            }
        }

        // Interactive list: match row title → handle
        $sections = Arr::get($sourceNode, 'data.metadata.fields.sections', []);
        if (! empty($sections) && is_array($sections)) {
            foreach ($sections as $sIdx => $section) {
                foreach ($section['rows'] ?? [] as $rIdx => $row) {
                    if (strtolower(trim($row['title'] ?? '')) === $msg) {
                        return 'a'.$sIdx.$rIdx;
                    }
                }
            }
        }

        return null;
    }

    private function deriveHandleFromChoiceId(array $sourceNode, string $replyId): ?string
    {
        $replyId = strtolower(trim($replyId));
        if ($replyId === '') {
            return null;
        }

        $buttons = Arr::get($sourceNode, 'data.metadata.fields.buttons', []);
        if (! empty($buttons) && is_array($buttons)) {
            $map = ['button1' => 'a', 'button2' => 'b', 'button3' => 'c'];
            foreach ($buttons as $id => $label) {
                if (trim((string) $label) !== '' && strtolower(trim((string) $id)) === $replyId) {
                    return $map[$id] ?? null;
                }
            }
        }

        $sections = Arr::get($sourceNode, 'data.metadata.fields.sections', []);
        if (! empty($sections) && is_array($sections)) {
            foreach ($sections as $sectionIndex => $section) {
                foreach ($section['rows'] ?? [] as $rowIndex => $row) {
                    if (strtolower(trim((string) ($row['id'] ?? ''))) === $replyId) {
                        return 'a'.$sectionIndex.$rowIndex;
                    }
                }
            }
        }

        return null;
    }

    private function hasStableChoiceIds(array $sourceNode): bool
    {
        $buttons = Arr::get($sourceNode, 'data.metadata.fields.buttons', []);
        if (! empty($buttons) && is_array($buttons)) {
            foreach ($buttons as $id => $label) {
                if (trim((string) $id) !== '' && trim((string) $label) !== '') {
                    return true;
                }
            }
        }

        $sections = Arr::get($sourceNode, 'data.metadata.fields.sections', []);
        if (! empty($sections) && is_array($sections)) {
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (trim((string) ($row['id'] ?? '')) !== '') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get the node type for a given node ID.
     */
    public function getNodeType(string $nodeId): ?string
    {
        $node = $this->getNodeById($nodeId);

        return $node['type'] ?? null;
    }

    /**
     * Check if a node is an action node.
     */
    public function isActionNode(string $nodeId): bool
    {
        return $this->getNodeType($nodeId) === 'action';
    }

    /**
     * Get the action type for an action node.
     */
    public function getActionType(string $nodeId): ?string
    {
        $node = $this->getNodeById($nodeId);

        return Arr::get($node, 'data.actionType');
    }

    /**
     * Get the fields (message content) for a message node.
     */
    public function getNodeFields(string $nodeId): ?array
    {
        $node = $this->getNodeById($nodeId);

        return Arr::get($node, 'data.metadata.fields');
    }

    /**
     * Get the action config for an action node.
     */
    public function getActionConfig(string $nodeId): ?array
    {
        $node = $this->getNodeById($nodeId);

        return Arr::get($node, 'data.config');
    }

    private function resolveTargetNode(array $edge): ?array
    {
        if (isset($edge['targetNode']['id'])) {
            $id = (string) $edge['targetNode']['id'];

            return $this->nodeMap[$id] ?? $edge['targetNode'];
        }

        if (isset($edge['target'])) {
            return $this->nodeMap[(string) $edge['target']] ?? null;
        }

        return null;
    }
}
