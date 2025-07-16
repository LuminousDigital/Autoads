<?php

namespace App\Lib;

use App\Constants\Status;
use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class NotificationSender
{
    private $isSingleNotification = false;
    private $modelClass;
    public $routePrefix;

    /**
     * Create a new instance with a model.
     *
     * @param string $modelName The model instance
     * @param string|null $routePrefix Optional route prefix (defaults to lowercase model name)
     * @throws \InvalidArgumentException If the model is invalid
     */
    public function __construct($modelName, $routePrefix = null)
    {
        $model = "App\\Models\\". ucfirst($modelName);

        if (!class_exists($model)) {
            throw new \InvalidArgumentException("Model class {$model} does not exist");
        }

        $this->modelClass = $model;
        $this->routePrefix = $routePrefix ?? strtolower($modelName);
    }

    /**
     * Create a notification sender for the given model.
     *
     * @param string $modelName
     * @param string $routePrefix
     * @return static
     */
    public static function for($modelName, $routePrefix = null)
    {
        return new static($modelName, $routePrefix);
    }

    /**
     * Send notification to all entities based on request parameters.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function notificationToAll(Request $request)
    {
        // Validate notification template
        if (!$this->isTemplateEnabled($request->via)) {
            return $this->redirectWithNotify('warning', 'Default notification template is not enabled');
        }

        // Handle entity selection
        if (!$this->handleSelectedEntities($request)) {
            return $this->redirectWithNotify('error', "Ensure that the entity field is populated when sending to a specific group");
        }

        // Get entities to notify
        $query = $this->getEntityQuery($request);
        $totalCount = $this->getTotalEntityCount($query, $request);

        if ($totalCount <= 0) {
            return $this->redirectWithNotify('error', "No notification recipients were found");
        }

        // Process notification image
        $imageUrl = $this->handlePushNotificationImage($request);

        // Get batch of entities and send notifications
        $entities = $this->getEntities($query, $request->start, $request->batch);
        $this->sendNotifications($entities, $request, $imageUrl);

        // Handle session and progress tracking
        return $this->manageSessionForNotification($totalCount, $request);
    }

    /**
     * Send notification to a single entity.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function notificationToSingle(Request $request, $id)
    {
        if (!$this->isTemplateEnabled($request->via)) {
            return $this->redirectWithNotify('warning', 'Default notification template is not enabled');
        }

        $this->isSingleNotification = true;
        $imageUrl = $this->handlePushNotificationImage($request);

        $entity = $this->modelClass::findOrFail($id);
        $this->sendNotifications($entity, $request, $imageUrl, true);

        return $this->redirectWithNotify("success", "Notification sent successfully");
    }

    /**
     * Check if the notification template is enabled for the given channel.
     *
     * @param string $via
     * @return bool
     */
    private function isTemplateEnabled(string $via): bool
    {
        return NotificationTemplate::where('act', 'DEFAULT')
            ->where("{$via}_status", Status::ENABLE)
            ->exists();
    }

    /**
     * Create a redirect response with notification message.
     *
     * @param string $type
     * @param string $message
     * @return \Illuminate\Http\RedirectResponse
     */
    private function redirectWithNotify(string $type, string $message)
    {
        return back()->withNotify([[$type, $message]]);
    }

    /**
     * Handle entity selection from the request or session.
     *
     * @param Request $request
     * @return bool
     */
    private function handleSelectedEntities(Request $request): bool
    {
        if ($request->being_sent_to == 'selected') {
            if (session()->has('SEND_NOTIFICATION')) {
                $request->merge(['entities' => session('SEND_NOTIFICATION')['entities']]);
            } elseif (!$request->entities || !is_array($request->entities) || empty($request->entities)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the entity query based on the scope.
     *
     * @param Request $request
     * @return Builder
     */
    private function getEntityQuery(Request $request): Builder
    {
        $scope = $request->being_sent_to;
        return $this->modelClass::oldest()->active()->$scope();
    }

    /**
     * Get the total count of entities to send notifications to.
     *
     * @param Builder $query
     * @param Request $request
     * @return int
     */
    private function getTotalEntityCount(Builder $query, Request $request): int
    {
        if (session()->has('SEND_NOTIFICATION')) {
            return session('SEND_NOTIFICATION')['total_entities'];
        }
        return (clone $query)->count() - ($request->start - 1);
    }

    /**
     * Handle push notification image upload and retrieval.
     *
     * @param Request $request
     * @return string|null
     */
    private function handlePushNotificationImage(Request $request): ?string
    {
        if ($request->via == 'push') {
            if ($request->hasFile('image')) {
                $imageUrl = fileUploader($request->image, getFilePath('push'));
                session()->put('PUSH_IMAGE_URL', $imageUrl);
                return $imageUrl;
            }
            return $this->isSingleNotification ? null : session('PUSH_IMAGE_URL');
        }
        return null;
    }

    /**
     * Get a batch of entities to notify.
     *
     * @param Builder $query
     * @param int $start
     * @param int $batch
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getEntities(Builder $query, int $start, int $batch)
    {
        return (clone $query)->skip($start - 1)->limit($batch)->get();
    }

    /**
     * Send notifications to entities.
     *
     * @param mixed $entities
     * @param Request $request
     * @param string|null $imageUrl
     * @param bool $isSingle
     * @return void
     */
    private function sendNotifications($entities, Request $request, ?string $imageUrl, bool $isSingle = false): void
    {
        $notificationData = [
            'subject' => $request->subject,
            'message' => $request->message,
        ];

        if (!$isSingle) {
            foreach ($entities as $entity) {
                notify($entity, 'DEFAULT', $notificationData, [$request->via], pushImage: $imageUrl);
            }
        } else {
            notify($entities, 'DEFAULT', $notificationData, [$request->via], pushImage: $imageUrl);
        }
    }

    /**
     * Manage session for batch notification processing.
     *
     * @param int $total
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    private function manageSessionForNotification(int $total, Request $request)
    {

        if (session()->has('SEND_NOTIFICATION')) {
            $session = session('SEND_NOTIFICATION');
            $session['total_sent'] += $session['batch'];
        } else {
            $session = $request->except('_token', 'image');
            $session['total_sent'] = $request->batch;
            $session['total_entities'] = $total;
        }

        $session['start'] = $session['total_sent'] + 1;

        if ($session['total_sent'] >= $total) {
            session()->forget('SEND_NOTIFICATION');
            $message = ucfirst($request->via) . " notifications were sent successfully";
            $url = route("admin.$this->routePrefix.notification.all");
        } else {
            session()->put('SEND_NOTIFICATION', $session);
            $message = "{$session['total_sent']} {$session['via']} notifications sent so far";
            $url = route("admin.$this->routePrefix.notification.all") . "?continue=yes";
        }

        return redirect($url)->withNotify([['success', $message]]);
    }
}
