@foreach ($postComments as $comment)
    <div class="border-start ps-2 mt-2 small">{{ $comment->body }}
        @if ($canReport)
            <form class="d-inline" method="post" action="{{ route('citizen.forum.report') }}">@csrf
                <input type="hidden" name="type" value="forum_comment"><input type="hidden" name="id" value="{{ $comment->id }}"><input type="hidden" name="reason" value="other">
                <button class="btn btn-link btn-sm p-0 ms-1 align-baseline" type="submit">Report</button>
            </form>
        @endif
    </div>
@endforeach
@if ($canComment || $canReport)
    <div class="row g-2 mt-2">
        @if ($canComment)
            <form class="col-md-7 d-flex gap-2" method="post" action="{{ route('citizen.forum.comment') }}">@csrf
                <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
                <input class="form-control form-control-sm" name="body" placeholder="Add a comment" required aria-label="Comment">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Comment</button>
            </form>
        @endif
        @if ($canReport)
            <form class="col-md-5 d-flex gap-2" method="post" action="{{ route('citizen.forum.report') }}">@csrf
                <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
                <select class="form-select form-select-sm" name="reason" aria-label="Reason for report">
                    @foreach ($reasons as $reason)<option value="{{ $reason }}">{{ ucfirst(str_replace('_', ' ', $reason)) }}</option>@endforeach
                </select>
                <button class="btn btn-outline-secondary btn-sm" type="submit">Report</button>
            </form>
        @endif
    </div>
@endif
