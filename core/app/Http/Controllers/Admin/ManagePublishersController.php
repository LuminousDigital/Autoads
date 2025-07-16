<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Lib\NotificationSender;
use App\Models\NotificationLog;
use App\Models\Transaction;
use App\Models\Publisher;
use App\Models\PublisherAd;
use App\Models\Withdrawal;
use App\Rules\FileTypeValidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class ManagePublishersController extends Controller
{
    public function allPublisher()
    {
        $pageTitle = 'All Publishers';
        $publishers = $this->userData();
        return view('admin.publishers.list', compact('pageTitle', 'publishers'));
    }

    public function activePublishers()
    {
        $pageTitle = 'Active Publishers';
        $publishers = $this->userData('active');
        return view('admin.publishers.list', compact('pageTitle', 'publishers'));
    }

    public function allBannedPublisher()
    {
        $pageTitle = 'Banned Publishers';
        $publishers = $this->userData('banned');
        return view('admin.publishers.list', compact('pageTitle', 'publishers'));
    }

    public function emailUnverifiedPublishers()
    {
        $pageTitle = 'Email Unverified Publishers';
        $publishers = $this->userData('emailUnverified');
        return view('admin.publishers.list', compact('pageTitle', 'publishers'));
    }

    public function mobileUnverifiedPublishers()
    {
        $pageTitle = 'Mobile Unverified Publishers';
        $publishers = $this->userData('mobileUnverified');
        return view('admin.publishers.list', compact('pageTitle', 'publishers'));
    }


    public function kycUnverifiedPublishers()
    {
        $pageTitle = 'KYC Unverified Publishers';
        $publishers = $this->userData('kycUnverified');
        return view('admin.publishers.list', compact('pageTitle', 'publishers'));
    }

    public function kycPendingPublishers()
    {
        $pageTitle = 'KYC Pending Publishers';
        $publishers = $this->userData('kycPending');
        return view('admin.publishers.list', compact('pageTitle', 'publishers'));
    }


    protected function userData($scope = null)
    {
        if ($scope) {
            $publishers = Publisher::$scope();
        } else {
            $publishers = Publisher::query();
        }
        return $publishers->searchable(['username', 'name', 'email'])->orderBy('id', 'desc')->paginate(getPaginate());
    }


    public function publisherDetails($id)
    {
        $publisher        = Publisher::findOrFail($id);
        $pageTitle        = 'Publisher Detail - ' . $publisher->username;
        $totalWithdraw    = Withdrawal::where('publisher_id', $publisher->id)->where('status', Status::PAYMENT_SUCCESS)->sum('amount');
        $totalAdvertise   = PublisherAd::where('publisher_id', $publisher->id)->get();
        $totalTransaction = Transaction::where('publisher_id', $publisher->id)->count();
        $countries        = json_decode(file_get_contents(resource_path('views/partials/country.json')));
        return view('admin.publishers.detail', compact('pageTitle', 'publisher', 'totalWithdraw', 'totalTransaction', 'countries', 'totalAdvertise'));
    }

    public function update(Request $request, $id)
    {
        $publisher = Publisher::findOrFail($id);
        $countryData = json_decode(file_get_contents(resource_path('views/partials/country.json')));
        $countryArray   = (array)$countryData;
        $countries      = implode(',', array_keys($countryArray));

        $countryCode    = $request->country;
        $country        = $countryData->$countryCode->country;
        $dialCode       = $countryData->$countryCode->dial_code;

        $request->validate([
            'firstname' => 'required|string|max:40',
            'lastname' => 'required|string|max:40',
            'email' => 'required|email|string|max:40|unique:publishers,email,' . $publisher->id,
            'mobile' => 'required|string|max:40',
            'country' => 'required|in:'.$countries,
        ]);

        $exists = Publisher::where('mobile',$request->mobile)->where('dial_code',$dialCode)->where('id','!=',$publisher->id)->exists();
        if ($exists) {
            $notify[] = ['error', 'The mobile number already exists.'];
            return back()->withNotify($notify);
        }

        $publisher->mobile = $request->mobile;
        $publisher->firstname = $request->firstname;
        $publisher->lastname = $request->lastname;
        $publisher->email = $request->email;

        $publisher->address = $request->address;
        $publisher->city = $request->city;
        $publisher->state = $request->state;
        $publisher->zip = $request->zip;
        $publisher->country_name = @$country;
        $publisher->dial_code = $dialCode;
        $publisher->country_code = $countryCode;

        $publisher->ev = $request->ev ? Status::VERIFIED : Status::UNVERIFIED;
        $publisher->sv = $request->sv ? Status::VERIFIED : Status::UNVERIFIED;
        $publisher->ts = $request->ts ? Status::ENABLE : Status::DISABLE;
        $publisher->kv = $request->kv ? Status::ENABLE : Status::DISABLE;

        $publisher->save();
        $notify[] = ['success', 'Publisher details updated successfully'];
        return back()->withNotify($notify);
    }

    public function kycDetails($id)
    {
        $pageTitle = 'KYC Details';
        $user = Publisher::findOrFail($id);
        return view('admin.publishers.kyc_detail', compact('pageTitle','user'));
    }

    public function kycApprove($id)
    {
        $user = Publisher::findOrFail($id);
        $user->kv = Status::KYC_VERIFIED;
        $user->save();

        notify($user,'KYC_APPROVE',[]);

        $notify[] = ['success','KYC approved successfully'];
        return to_route('admin.publisher.kyc.pending')->withNotify($notify);
    }

    public function kycReject(Request $request,$id)
    {
        $request->validate([
            'reason'=>'required'
        ]);
        $user = Publisher::findOrFail($id);
        $user->kv = Status::KYC_UNVERIFIED;
        $user->kyc_rejection_reason = $request->reason;
        $user->save();

        notify($user,'KYC_REJECT',[
            'reason'=>$request->reason
        ]);

        $notify[] = ['success','KYC rejected successfully'];
        return to_route('admin.publisher.kyc.pending')->withNotify($notify);
    }

    public function login($id)
    {
        $publisher = Publisher::findOrFail($id);

        if(auth()->guard('advertiser')->check())
        {
            auth()->guard('advertiser')->logout();
        }
        Auth::guard('publisher')->login($publisher);

        return to_route('publisher.dashboard');
    }

    public function status(Request $request, $id)
    {
        $publisher = Publisher::findOrFail($id);
        if ($publisher->status == Status::ADVERTISER_ACTIVE) {
            $request->validate([
                'reason' => 'required|string|max:255'
            ]);
            $publisher->status = Status::ADVERTISER_BAN;
            $publisher->ban_reason = $request->reason;
            $notify[] = ['success', 'Publisher banned successfully'];
        } else {
            $publisher->status = Status::ADVERTISER_ACTIVE;
            $publisher->ban_reason = null;
            $notify[] = ['success', 'Publisher unbanned successfully'];
        }
        $publisher->save();
        return back()->withNotify($notify);
    }

    public function showNotificationSingleForm($id)
    {
        $publisher = Publisher::findOrFail($id);

        if (!gs('en') && !gs('sn') && !gs('pn')) {
            $notify[] = ['warning','Notification options are disabled currently'];
            return to_route('admin.publisher.detail',$publisher->id)->withNotify($notify);
        }

        $pageTitle = 'Send Notification to ' . $publisher->username;
        return view('admin.publishers.notification_single', compact('pageTitle', 'publisher'));
    }

    public function sendNotificationSingle(Request $request, $id)
    {
        $request->validate([
            'message' => 'required',
            'via'     => 'required|in:email,sms,push',
            'subject' => 'required_if:via,email,push',
            'image'   => ['nullable', 'image', new FileTypeValidate(['jpg', 'jpeg', 'png'])],
        ]);

        if (!gs('en') && !gs('sn') && !gs('pn')) {
            $notify[] = ['warning', 'Notification options are disabled currently'];
            return to_route('admin.dashboard')->withNotify($notify);
        }

        return NotificationSender::for('publisher')->notificationToSingle($request, $id);
    }

    public function showNotificationAllForm()
    {
        if (!gs('en') && !gs('sn') && !gs('pn')) {
            $notify[] = ['warning', 'Notification options are disabled currently'];
            return to_route('admin.dashboard')->withNotify($notify);
        }

        $notifyToPublisher = Publisher::notifyToPublisher();
        $publishers        = Publisher::active()->count();

        $pageTitle    = 'Notification to Verified Publishers';

        if (session()->has('SEND_NOTIFICATION') && !request()->continue) {
            session()->forget('SEND_NOTIFICATION');
        }

        return view('admin.publishers.notification_all', compact('pageTitle', 'publishers', 'notifyToPublisher'));
    }

    public function sendNotificationAll(Request $request)
    {
        $request->validate([
            'via'                          => 'required|in:email,sms,push',
            'message'                      => 'required',
            'subject'                      => 'required_if:via,email,push',
            'start'                        => 'required|integer|gte:1',
            'batch'                        => 'required|integer|gte:1',
            'being_sent_to'                => 'required',
            'cooling_time'                 => 'required|integer|gte:1',
            'number_of_top_deposited_publisher' => 'required_if:being_sent_to,topDepositedPublishers|integer|gte:0',
            'number_of_days'               => 'required_if:being_sent_to,notLoginPublishers|integer|gte:0',
            'image'                        => ["nullable", 'image', new FileTypeValidate(['jpg', 'jpeg', 'png'])],
        ], [
            'number_of_days.required_if'               => "Number of days field is required",
            'number_of_top_deposited_publisher.required_if' => "Number of top deposited publisher field is required",
        ]);

        if (!gs('en') && !gs('sn') && !gs('pn')) {
            $notify[] = ['warning', 'Notification options are disabled currently'];
            return to_route('admin.dashboard')->withNotify($notify);
        }

        return NotificationSender::for('publisher')->notificationToAll($request);
    }

    public function countBySegment($methodName){
        return Publisher::active()->$methodName()->count();
    }

    public function list()
    {
        $query = Publisher::active();

        if (request()->search) {
            $query->where(function ($q) {
                $q->where('email', 'like', '%' . request()->search . '%')->orWhere('username', 'like', '%' . request()->search . '%');
            });
        }
        $publishers = $query->orderBy('id', 'desc')->paginate(getPaginate());

        return response()->json([
            'success' => true,
            'publishers'   => $publishers,
            'more'    => $publishers->hasMorePages()
        ]);
    }

    public function notificationLog($id)
    {
        $publisher = Publisher::findOrFail($id);
        $pageTitle = 'Notifications Sent to ' . $publisher->username;
        $logs = NotificationLog::where('publisher_id', $id)->with('publisher')->orderBy('id', 'desc')->paginate(getPaginate());
        return view('admin.reports.publisher_notification_history', compact('pageTitle', 'logs', 'publisher'));
    }
}
