<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Support\ApiPagination;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $paginator = $request->user()->notifications()
            ->paginate($request->integer('perPage', 15), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, NotificationResource::class));
    }

    public function markRead(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return new NotificationResource($record);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
