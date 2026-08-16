<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\PreCreatedReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PreCreatedReplyController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $wantsJson = $request->wantsJson();

        $query = PreCreatedReply::where('organization_id', $organizationId);
        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $terms = preg_split('/[^\p{L}\p{N}_]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $fullTextTerms = collect($terms)
                ->filter(fn (string $term) => mb_strlen($term) >= 4)
                ->values();

            if ($fullTextTerms->count() === count($terms) && $fullTextTerms->isNotEmpty()) {
                $fullTextSearch = $fullTextTerms
                    ->map(fn (string $term) => '+' . $term . '*')
                    ->implode(' ');
                $query->whereFullText('title', $fullTextSearch, ['mode' => 'boolean']);
            } else {
                // MySQL full-text indexes ignore very short words.
                $query->where('title', 'like', '%' . $search . '%');
            }
        }

        $rows = $query
            ->select($wantsJson
                ? ['id', 'uuid', 'title', 'type', 'content', 'created_at']
                : ['id', 'uuid', 'title', 'type', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();

        if ($wantsJson) {
            return \App\Http\Resources\PreCreatedReplyResource::collection($rows);
        }

        return Inertia::render('User/PreCreatedReplies/Index', [
            'title' => __('Pre-created Replies'),
            'rows' => \App\Http\Resources\PreCreatedReplyResource::collection($rows),
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('User/PreCreatedReplies/Create', [
            'title' => __('Create Reply'),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:text,quick_reply,header_footer,link',
            'content' => 'required|array',
            'content.body' => 'required|string',
            'content.buttons' => 'nullable|required_if:type,quick_reply,header_footer|array',
            'content.buttons.*.text' => 'nullable|required_if:type,quick_reply,header_footer|string',
            'content.link_text' => 'nullable|required_if:type,link|string',
            'content.link_url' => 'nullable|required_if:type,link|url',
        ]);

        $organizationId = session()->get('current_organization');

        PreCreatedReply::create([
            'uuid' => Str::uuid(),
            'organization_id' => $organizationId,
            'title' => $request->title,
            'type' => $request->type,
            'content' => $request->content,
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('pre-created-replies.index')->with('status', [
            'type' => 'success',
            'message' => 'Reply created successfully!',
        ]);
    }

    public function edit($uuid)
    {
        $reply = PreCreatedReply::where('uuid', $uuid)->firstOrFail();

        return Inertia::render('User/PreCreatedReplies/Edit', [
            'title' => __('Edit Reply'),
            'reply' => $reply,
        ]);
    }

    public function update(Request $request, $uuid)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:text,quick_reply,header_footer,link',
            'content' => 'required|array',
            'content.body' => 'required|string',
            'content.buttons' => 'nullable|required_if:type,quick_reply,header_footer|array',
            'content.buttons.*.text' => 'nullable|required_if:type,quick_reply,header_footer|string',
            'content.link_text' => 'nullable|required_if:type,link|string',
            'content.link_url' => 'nullable|required_if:type,link|url',
        ]);

        $reply = PreCreatedReply::where('uuid', $uuid)->firstOrFail();

        $reply->update([
            'title' => $request->title,
            'type' => $request->type,
            'content' => $request->content,
        ]);

        return redirect()->route('pre-created-replies.index')->with('status', [
            'type' => 'success',
            'message' => 'Reply updated successfully!',
        ]);
    }

    public function destroy($uuid)
    {
        $reply = PreCreatedReply::where('uuid', $uuid)->firstOrFail();
        $reply->delete();

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Reply deleted successfully!',
        ]);
    }
}
