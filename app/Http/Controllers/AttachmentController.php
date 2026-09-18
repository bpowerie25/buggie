<?php

namespace App\Http\Controllers;

use App\Enums\IssueEventType;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Issue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * What may be uploaded.
     *
     * An allowlist rather than a blocklist, and deliberately without SVG: it is an
     * XML document that can carry script, and anything we serve back to a browser
     * from our own origin can act on our behalf.
     */
    private const ALLOWED_MIMES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain', 'text/csv', 'application/json',
        'application/zip',
    ];

    private const MAX_KILOBYTES = 10240;

    public function store(Request $request, Issue $issue): RedirectResponse|JsonResponse
    {
        $this->authorize('comment', $issue);

        $validated = $request->validate([
            'file' => [
                'required', 'file',
                'max:'.self::MAX_KILOBYTES,
                'mimetypes:'.implode(',', self::ALLOWED_MIMES),
            ],
            'comment_id' => ['nullable', 'integer'],
        ]);

        // Scoped, so a comment id from another workspace resolves to nothing.
        $attachable = $issue;

        if ($validated['comment_id'] ?? null) {
            $comment = Comment::where('issue_id', $issue->id)
                ->findOrFail($validated['comment_id']);

            abort_unless($comment->user_id === $request->user()->id, 403);

            $attachable = $comment;
        }

        $attachment = $this->save($request->file('file'), $attachable, $issue, $request);

        if ($attachable instanceof Issue) {
            $issue->recordEvent(
                IssueEventType::AttachmentAdded,
                ['filename' => $attachment->filename],
                $request->user(),
            );
        }

        // The editor uploads in the background and needs the URL back.
        if ($request->wantsJson()) {
            return response()->json([
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'url' => route('attachments.show', $attachment),
                'is_image' => $attachment->isImage(),
            ], 201);
        }

        return back()->with('success', "{$attachment->filename} attached.");
    }

    /**
     * Stream an attachment.
     *
     * Never public and never a redirect to a raw file: screenshots and logs routinely
     * contain a customer's production data, so every read goes through the issue's
     * own visibility check.
     */
    public function show(Attachment $attachment): StreamedResponse
    {
        $issue = $this->issueFor($attachment);

        abort_if($issue === null, 404);

        $this->authorize('view', $issue);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $isImage = $attachment->isImage();

        return $disk->response($attachment->path, $attachment->filename, [
            'Content-Type' => $attachment->mime,
            // Browsers guessing at content type is how an uploaded file becomes a
            // script. Images render inline; everything else downloads.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($isImage ? 'inline' : 'attachment')
                .'; filename="'.addslashes($attachment->filename).'"',

            // A Content-Security-Policy carrying `sandbox` puts the response in an
            // opaque origin, which stops it rendering as an <img> in the issue page.
            // Images are already restricted to a raster allowlist — no SVG — so
            // inline display is safe without it. Everything else is force-downloaded
            // and never interpreted, and gets the strict policy for the case where
            // somebody navigates straight to the URL.
            ...($isImage ? [] : ['Content-Security-Policy' => "default-src 'none'; sandbox"]),
        ]);
    }

    public function destroy(Request $request, Attachment $attachment): RedirectResponse
    {
        $issue = $this->issueFor($attachment);

        abort_if($issue === null, 404);

        $this->authorize('update', $issue);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    private function save(
        UploadedFile $file,
        Issue|Comment $attachable,
        Issue $issue,
        Request $request,
    ): Attachment {
        // Stored under a generated name: the original is kept as a label only, so a
        // crafted filename cannot escape the directory or collide with anything.
        $path = $file->store("workspaces/{$issue->workspace_id}/attachments", 'local');

        $dimensions = $this->dimensions($file);

        return Attachment::create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'disk' => 'local',
            'path' => $path,
            'filename' => $this->safeName($file->getClientOriginalName()),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'uploaded_by_id' => $request->user()->id,
        ]);
    }

    /** @return array{0: int|null, 1: int|null} */
    private function dimensions(UploadedFile $file): array
    {
        if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath());

        return [$size[0] ?? null, $size[1] ?? null];
    }

    /** Keep something recognisable, drop anything that could be read as a path. */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\w\s.\-()]/u', '', $name);

        return mb_substr(trim($name) ?: 'attachment', 0, 120);
    }

    private function issueFor(Attachment $attachment): ?Issue
    {
        $attachable = $attachment->attachable;

        return match (true) {
            $attachable instanceof Issue => $attachable,
            $attachable instanceof Comment => $attachable->issue,
            default => null,
        };
    }
}
