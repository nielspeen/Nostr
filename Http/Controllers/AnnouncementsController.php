<?php

namespace Modules\Nostr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mailbox;
use Illuminate\Http\Request;
use Modules\Nostr\Entities\Announcement;
use App\Nostr\NostrMailbox;
use Modules\Nostr\Services\AnnouncementPublisher;

class AnnouncementsController extends Controller
{
    public function index($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('update', $mailbox);
        $query = Announcement::where('mailbox_id', $mailbox->id);
        $editing = $request->query('edit') ? (clone $query)->findOrFail($request->query('edit')) : null;
        return view('nostr::announcements', [
            'mailbox' => $mailbox, 'editing' => $editing,
            'announcements' => $query->orderBy('updated_at', 'desc')->paginate(20),
        ]);
    }

    public function publish($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('update', $mailbox);
        $cfg = NostrMailbox::forMailbox($mailbox->id);
        if (!$cfg->isReady()) {
            return back()->withInput()->with('flash_error_floating', __('Configure Nostr for this mailbox first.'));
        }
        $announcement = $request->input('announcement_id')
            ? Announcement::where('mailbox_id', $mailbox->id)->findOrFail($request->input('announcement_id'))
            : new Announcement();
        $publisher = new AnnouncementPublisher();
        try {
            if ($request->input('action') !== 'retry') {
                $announcement->mailbox_id = $mailbox->id;
                $announcement->identifier = $announcement->identifier ?: bin2hex(random_bytes(16));
                $privateKey = $announcement->exists ? $cfg->getPrivateKeyFor($announcement->event['pubkey']) : $cfg->getPrivateKey();
                if (!$privateKey) {
                    throw new \RuntimeException('The signing key is no longer available.');
                }
                $announcement->event = $publisher->build($request->only(['title', 'summary', 'body', 'incident', 'expires_at']),
                    $announcement->identifier, $privateKey, $announcement->event ?: []);
                $announcement->relay_results = [];
            } elseif (!$announcement->exists) {
                abort(404);
            }
            $results = $publisher->publish($announcement, $cfg);
            $accepted = count(array_filter($results, function ($result) { return !empty($result['ok']); }));
            $key = $accepted === count($results) && $accepted ? 'flash_success_floating' : 'flash_error_floating';
            \Session::flash($key, __('Accepted by :count of :total relays. Retry below if any failed.', ['count' => $accepted, 'total' => count($results)]));
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('flash_error_floating', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('flash_error_floating', $e->getMessage());
        }
        return redirect()->route('mailboxes.nostr.announcements', ['id' => $mailbox->id]);
    }
}
