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
        
        $rows = PreCreatedReply::where('organization_id', $organizationId)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        if ($request->wantsJson()) {
            return \App\Http\Resources\PreCreatedReplyResource::collection($rows);
        }

        return Inertia::render('User/PreCreatedReplies/Index', [
            'title' => __('Pre-created Replies'),
            'rows' => \App\Http\Resources\PreCreatedReplyResource::collection($rows),
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
            'content' => 'required',
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
            'content' => 'required',
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
