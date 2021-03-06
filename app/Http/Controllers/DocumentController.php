<?php

namespace App\Http\Controllers;

use App\Http\Requests\Document as Requests;
use App\Events\DocumentPublished;
use App\Events\SupportVoteChanged;
use App\Models\User;
use App\Models\Doc as Document;
use App\Models\DocContent as DocumentContent;
use App\Models\DocMeta as DocumentMeta;
use App\Models\Sponsor;
use App\Services;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Storage;

class DocumentController extends Controller
{
    protected $documentService;

    /**
     * Instantiate a new controller instance.
     *
     * @return void
     */
    public function __construct(Services\Documents $documentService)
    {
        $this->documentService = $documentService;

        $this->middleware('auth')->except(['index', 'show']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Requests\Index $request)
    {
        $orderField = $request->input('order', 'updated_at');
        $orderDir = $request->input('order_dir', 'DESC');
        $discussionStates = $request->input('discussion_state', null);

        $documentsQuery = Document
            ::where('is_template', '!=', '1');

        if ($discussionStates) {
            $documentsQuery->whereIn('discussion_state', $discussionStates);
        }

        if ($request->has('q')) {
            $documentsQuery->search($request->get('q'));
        }

        // So this part of the query is a little crazy. It basically grabs
        // documents on a per-sponsor basis. For any given sponsor any one can see
        // the sponsors documents that are published, but for users that belong
        // to a sponsor, they can also see the documents in their sponsors in
        // other publish states.
        //
        // As the number of sponsors in the systems grows so does the size of
        // this query, that could become an issue at some point.
        //
        // Default behavior should be to filter to only documents that are
        // public or the user owns (i.e., they are a member of the sponsor that
        // owns them with sufficient privileges to view the document in it's
        // current state)
        //
        // If the user specifies publish states and no sponsors, view all
        // published documents (if that was a publish state requested) and the
        // publish states allowed for each sponsor the user belongs to
        //
        // If the user specifies some sponsors but no explicit publish states,
        // should show every document visible to the user in those sponsors, for
        // some they might be able to see all the publish states, for others
        // maybe only published (e.g., the sponsors they are not a part of)
        //
        // If the user specifies some of both, then of course we want to
        // restrict ourselves to only documents that belong to that sponsor and
        // within those, only the ones they have sufficient permission to view
        // for the each sponsor

        // grab the sponsor ids we want to concern ourselves with, by default we
        // don't want to limit ourselves at all, i.e., we want to make
        // available all possible documents, so we default to all sponsors
        $sponsorIds = [];
        if (!$request->has('sponsor_id')) {
            $sponsorIds = Sponsor::select('id')->pluck('id')->toArray();
        } else {
            $sponsorIds = $request->input('sponsor_id');
        }

        // if the user is logged in, lookup any sponsors they belong to so we
        // can widen the possible publish states we will allow for those sponsor
        // documents
        $userSponsorIds = [];
        if ($request->user()) {
            if ($request->user()->isAdmin()) {
                // we'll just act like an admin is a member of every sponsor
                $userSponsorIds = Sponsor::select('id')->pluck('id')->flip()->toArray();
            } else {
                $userSponsorIds = $request->user()->sponsors()->pluck('sponsors.id')->flip()->toArray();
            }
        }

        // grab all the publish states we want to consider, by default we'll
        // include all non-deleted states
        $requestedPublishStates = [];
        if (!$request->has('publish_state')) {
            $requestedPublishStates = [
                Document::PUBLISH_STATE_PUBLISHED,
                Document::PUBLISH_STATE_UNPUBLISHED,
                Document::PUBLISH_STATE_PRIVATE,
            ];
        } elseif ($request->has('publish_state') && in_array('all', $request->input('publish_state'))) {
            $requestedPublishStates = Document::validPublishStates();
        } else {
            $requestedPublishStates = $request->input('publish_state');
        }

        if (in_array(Document::PUBLISH_STATE_DELETED_ADMIN, $requestedPublishStates)
            || in_array(Document::PUBLISH_STATE_DELETED_USER, $requestedPublishStates)) {
            $documentsQuery->withTrashed();
        }

        // build up a map of which publish states the user can see for each sponsor
        $sponsorIdsToPubStates = [];
        foreach ($sponsorIds as $sponsorId) {
            $pubStates = [];
            // by default, you can only see published documents
            $possiblePubStates = [Document::PUBLISH_STATE_PUBLISHED];
            if (isset($userSponsorIds[$sponsorId])) {
                // if you are a member of the sponsor in any role, you can see
                // the document in whatever state it's in
                $possiblePubStates = Document::validPublishStates();
            }
            $pubStates = array_intersect($possiblePubStates, $requestedPublishStates);
            $sponsorIdsToPubStates[$sponsorId] = $pubStates;
        }

        // here's the actual query part, restricting the selected documents
        // to only those the user has permission to see
        $documentsQuery->where(function ($documentsQuery) use ($sponsorIdsToPubStates) {
            // add an OR clause for every requested sponsor and publish states combo
            foreach ($sponsorIdsToPubStates as $sponsorId => $pubStates) {
                $documentsQuery->orWhere(function ($query) use ($sponsorId, $pubStates) {
                    $query->whereHas('sponsors', function ($q) use ($sponsorId, $pubStates) {
                        $q->where('id', $sponsorId);
                    });
                    $query->whereIn('publish_state', $pubStates);
                });
            }
        });

        // execute the query
        $documents = null;
        $limit = $request->input('limit', 12);
        $page = $request->input('page', 1);
        $orderedAndLimitedDocuments = collect([]);
        $totalCount = 0;

        if ($orderField === 'activity') {
            // ordering by activity is special

            // we limit the query to only the documents that we have activity
            // data on, which currently means published documents with open
            // discussion states, we could not do this and simply have all
            // other documents sorted to the bottom instead of excluded
            $unorderedDocuments = $documentsQuery
                ->whereIn('docs.id', Document::getActiveIds())
                ->get()
                ;

            $orderedAndLimitedDocuments = Document::sortByActive($unorderedDocuments)
                ->forPage($page, $limit);

            $totalCount = count(Document::getActiveIds()); // total items possible
        } else {
            // do the count query first, ordering doesn't matter to it, we
            // can't just use the normal pagination methods for this due to
            // how the search query works with it's extra select statements
            // and havings
            $totalCount = with(clone $documentsQuery)
                ->addSelect(\DB::raw('count(*) as count'))
                ->first()
                ;
            $totalCount = $totalCount ? $totalCount->count : 0;

            // if a specific sort order wasn't requested and they had a search
            // query, prioritize ordering by search relevance
            if ($request->has('q')
                && (!$request->has('order') || $orderField === 'relevance')
            ) {
                $documentsQuery->orderByRelevance();
            } elseif ($orderField === 'relevance' && !$request->has('q')) {
                // relevance ordering only makes sense with a query
                flash(trans('messages.relevance_ordering_warning'));
                $documentsQuery->orderBy('updated_at', 'desc');
            } else {
                $documentsQuery->orderBy($orderField, $orderDir);
            }

            $orderedAndLimitedDocuments = $documentsQuery
                ->forPage($page, $limit)
                ->get()
                ;
        }

        $documents = new LengthAwarePaginator(
            $orderedAndLimitedDocuments,
            $totalCount, // total items possible
            $limit,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page'
            ]
        );

        // for the query builder modal
        $sponsors = Sponsor::where('status', Sponsor::STATUS_ACTIVE)->get();
        $publishStates = static::validPublishStatesForQuery();
        $discussionStates = Document::validDiscussionStates();

        // draw the page
        return view('documents.list', compact([
            'documents',
            'sponsors',
            'publishStates',
            'discussionStates',
        ]));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Requests\Store $request)
    {
        $title = $request->input('title');
        $slug = str_slug($title, '-');

        // If the slug is taken
        if (Document::where('slug', $slug)->count()) {
            $counter = 0;
            $tooMany = 10;
            do {
                if ($counter > $tooMany) {
                    flash(trans('messages.document.title_invalid'));
                    return back()->withInput();
                }
                $counter++;
                $new_slug = $slug . '-' . str_random(8);
            } while (Document::where('slug', $new_slug)->count());

            $slug = $new_slug;
        }

        $document = new Document();
        $document->title = $title;
        $document->slug = $slug;
        $document->save();

        $document->content()->create([
            'content' => 'New Document Content',
        ]);

        $document->sponsors()->sync([$request->input('sponsor_id')]);

        flash(trans('messages.document.created'));
        return redirect()->route('documents.manage.settings', $document);
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Requests\View $request, Document $document)
    {
        $supportCount = $document->support;
        $opposeCount = $document->oppose;
        $userSupport = null;
        $useDarkContentBg = true;

        // Get current user support status, if logged in
        if ($request->user()) {
            $existingSupportMeta = $this->getUserSupportMeta($request->user(), $document);

            if ($existingSupportMeta) {
                $userSupport = (bool) $existingSupportMeta->meta_value;
            }
        }

        $documentPages = $document->content()->paginate(1);
        $comments = $document->comments()->latest()->paginate(15, ['*'], 'comment_page');

        return view('documents.show', compact([
            'document',
            'documentPages',
            'comments',
            'supportCount',
            'opposeCount',
            'userSupport',
            'useDarkContentBg',
        ]));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Requests\Update $request, Document $document)
    {
        $oldPublishState = $document->publish_state;

        $document->update($request->all());

        if ($oldPublishState !== Document::PUBLISH_STATE_PUBLISHED
            && $request->input('publish_state') === Document::PUBLISH_STATE_PUBLISHED
        ) {
            event(new DocumentPublished($document, $request->user()));
        }

        $document->setIntroText($request->input('introtext'));

        // update content for correct page
        $pageContent = $document->content()->where('page', $request->input('page', 1))->first();

        if ($pageContent) {
            $pageContent->content = $request->input('page_content', '');
            $pageContent->save();
        }

        flash(trans('messages.document.updated'));
        return redirect()->route('documents.manage.settings', [
            'document' => $document,
            'page' => $request->input('page', 1),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(Requests\Edit $request, Document $document)
    {
        if ($request->user()->isAdmin()) {
            $document->publish_state = Document::PUBLISH_STATE_DELETED_ADMIN;
        } else {
            $document->publish_state = Document::PUBLISH_STATE_DELETED_USER;
        }

        $document->save();

        $document->annotations()->withoutGlobalScope('visible')->delete();
        $document->doc_meta()->delete();
        $document->content()->delete();

        $document->delete();

        $restoreUrl = route('documents.restore', $document);
        flash(trans('messages.document.deleted', [
            'restoreLinkOpen' => "<a href='$restoreUrl'>",
            'restoreLinkClosed' => '</a>',
        ]))->important();
        return back();
    }

    public function restore(Requests\Edit $request, Document $document)
    {
        if ($document->publish_state === Document::PUBLISH_STATE_DELETED_ADMIN) {
            if (!$request->user()->isAdmin()) {
                abort(403, 'Unauthorized');
            }
        }

        DocumentMeta::withTrashed()->where('doc_id', $document->id)->restore();
        $document->content()->withTrashed()->restore();
        $document->annotations()->withTrashed()->withoutGlobalScope('visible')->restore();

        $document->restore();
        $document->publish_state = Document::PUBLISH_STATE_UNPUBLISHED;
        $document->save();

        flash(trans('messages.document.restored'));
        return redirect()->route('documents.manage.settings', $document);
    }

    public function storePage(Requests\Edit $request, Document $document)
    {
        $lastPage = $document->content()->max('page') ?: 0;
        $page = $lastPage + 1;

        $documentContent = new DocumentContent();
        $documentContent->content = $request->input('content', '');
        $documentContent->page = $page;
        $document->content()->save($documentContent);

        flash(trans('messages.document.page_added'));
        return redirect()->route('documents.manage.settings', ['document' => $document, 'page' => $page]);
    }

    public function updateSupport(Requests\PutSupport $request, Document $document)
    {
        $support = (bool) $request->input('support');

        $existingDocumentMeta = $this->getUserSupportMeta($request->user(), $document);

        $oldValue = null;
        $newValue = $support;
        if ($existingDocumentMeta) {
            $oldValue = (bool) $existingDocumentMeta->meta_value;

            // are we removing support/opposition?
            if ($oldValue === $support) {
                $newValue = null;
                $existingDocumentMeta->forceDelete();
            } else {
                $existingDocumentMeta->meta_value = $support;
                $existingDocumentMeta->save();
            }
        } else {
            // create new one!
            $documentMeta = new DocumentMeta();
            $documentMeta->doc_id = $document->id;
            $documentMeta->user_id = $request->user()->id;
            $documentMeta->meta_key = 'support';
            $documentMeta->meta_value = $support;
            $documentMeta->save();
        }

        event(new SupportVoteChanged($oldValue, $newValue, $document, $request->user()));

        flash(trans('messages.document.update_support'));
        return redirect()->route('documents.show', $document);
    }

    public function manageSettings(Request $request, Document $document)
    {
        $this->authorize('viewManage', $document);

        $sponsors = Sponsor::where('status', Sponsor::STATUS_ACTIVE)->get();
        $publishStates = Document::validPublishStates();
        $discussionStates = Document::validDiscussionStates();
        $pages = $document->content()->paginate(1);

        return view('documents.manage.settings', compact([
            'document',
            'sponsors',
            'publishStates',
            'discussionStates',
            'pages',
        ]));
    }

    public function manageComments(Request $request, Document $document)
    {
        $this->authorize('viewManage', $document);

        $allFlaggedComments = $document->allCommentsWithHidden->filter(function ($comment) {
            return $comment->flags_count;
        });

        $unhandledComments = new Collection();
        $handledComments = new Collection();

        $allFlaggedComments->filter(function($c) use ($unhandledComments, $handledComments) {
            if ($c->isResolved() || $c->isHidden()) {
                $handledComments->push($c);
            } else {
                $unhandledComments->push($c);
            }
        });

        return view('documents.manage.comments', compact([
            'document',
            'unhandledComments',
            'handledComments',
        ]));
    }

    public static function validPublishStatesForQuery()
    {
       return ['all'] + Document::validPublishStates();
    }

    protected function getUserSupportMeta(User $user, Document $document)
    {
        return DocumentMeta::where('user_id', $user->id)
            ->where('meta_key', '=', 'support')
            ->where('doc_id', '=', $document->id)
            ->first();
    }
}
