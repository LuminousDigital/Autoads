<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Lib\NotificationSender;
use App\Models\Advertise;
use App\Models\Deposit;
use App\Models\NotificationLog;
use App\Models\Transaction;
use App\Models\Advertiser;
use App\Rules\FileTypeValidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ManageAdvertisersController extends Controller
{

    public function allAdvertiser()
    {
        $pageTitle = 'All Advertisers';
        $advertisers = $this->userData();
        return view('admin.advertisers.list', compact('pageTitle', 'advertisers'));
    }

    public function activeAdvertisers()
    {

        $pageTitle = 'Active Advertisers';
        $advertisers = $this->userData('active');
        return view('admin.advertisers.list', compact('pageTitle', 'advertisers'));
    }

    public function allBannedAdvertiser()
    {
        $pageTitle = 'Banned Advertisers';
        $advertisers = $this->userData('banned');
        return view('admin.advertisers.list', compact('pageTitle', 'advertisers'));
    }

    public function emailUnverifiedAdvertisers()
    {
        $pageTitle = 'Email Unverified Advertisers';
        $advertisers = $this->userData('emailUnverified');
        return view('admin.advertisers.list', compact('pageTitle', 'advertisers'));
    }

    public function mobileUnverifiedAdvertisers()
    {
        $pageTitle = 'Mobile Unverified Advertisers';
        $advertisers = $this->userData('mobileUnverified');
        return view('admin.advertisers.list', compact('pageTitle', 'advertisers'));
    }


    protected function userData($scope = null)
    {
        if ($scope) {
            $advertisers = Advertiser::$scope();
        } else {
            $advertisers = Advertiser::query();
        }
        return $advertisers->searchable(['username', 'name', 'email'])->orderBy('id', 'desc')->paginate(getPaginate());
    }


    public function advertiserDetails($id)
    {
        $advertiser = Advertiser::findOrFail($id);
        $pageTitle = 'Advertiser Detail - ' . $advertiser->username;
        $totalAdvertise = Advertise::where('advertiser_id', $id)->where('status', Status::ACTIVE)->count();
        $totalDeposit = Deposit::where('advertiser_id', $advertiser->id)->where('status', Status::PAYMENT_SUCCESS)->sum('amount');
        $totalTransaction = Transaction::where('advertiser_id', $advertiser->id)->count();
        $countries = json_decode(file_get_contents(resource_path('views/partials/country.json')));
        return view('admin.advertisers.detail', compact('pageTitle', 'advertiser', 'totalDeposit', 'totalTransaction', 'countries', 'totalAdvertise'));
    }


    public function update(Request $request, $id)
    {
        $publisher = Advertiser::findOrFail($id);
        $countryData = json_decode(file_get_contents(resource_path('views/partials/country.json')));
        $countryArray   = (array)$countryData;
        $countries      = implode(',', array_keys($countryArray));

        $countryCode    = $request->country;
        $country        = $countryData->$countryCode->country;
        $dialCode       = $countryData->$countryCode->dial_code;

        $request->validate([
            'firstname' => 'required|string|max:40',
            'lastname' => 'required|string|max:40',
            'email' => 'required|email|string|max:40|unique:advertisers,email,' . $publisher->id,
            'mobile' => 'required|string|max:40',
            'country' => 'required|in:'.$countries,
        ]);

        $exists = Advertiser::where('mobile',$request->mobile)->where('dial_code',$dialCode)->where('id','!=',$publisher->id)->exists();
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


        $publisher->save();
        $notify[] = ['success', 'Advertiser details updated successfully'];
        return back()->withNotify($notify);
    }


    public function addSubBalance(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|gt:0',
            'act' => 'required|in:add,sub',
            'remark' => 'required|string|max:255',
        ]);

        $advertiser = Advertiser::findOrFail($id);
        $amount = $request->amount;
        $trx = getTrx();

        $transaction = new Transaction();

        if ($request->act == 'add') {
            $advertiser->balance += $amount;

            $transaction->trx_type = '+';
            $transaction->remark = 'balance_add';

            $notifyTemplate = 'BAL_ADD';

            $notify[] = ['success', gs('cur_sym') . $amount . ' added successfully'];
        } else {
            if ($amount > $advertiser->balance) {
                $notify[] = ['error', $advertiser->username . ' doesn\'t have sufficient balance.'];
                return back()->withNotify($notify);
            }

            $advertiser->balance -= $amount;

            $transaction->trx_type = '-';
            $transaction->remark = 'balance_subtract';

            $notifyTemplate = 'BAL_SUB';
            $notify[] = ['success', gs('cur_sym') . $amount . ' subtracted successfully'];
        }

        $advertiser->save();

        $transaction->advertiser_id = $advertiser->id;
        $transaction->amount = $amount;
        $transaction->post_balance = $advertiser->balance;
        $transaction->charge = 0;
        $transaction->trx =  $trx;
        $transaction->details = $request->remark;
        $transaction->save();

        notify($advertiser, $notifyTemplate, [
            'trx' => $trx,
            'amount' => showAmount($amount, currencyFormat:false),
            'remark' => $request->remark,
            'post_balance' => showAmount($advertiser->balance, currencyFormat:false)
        ]);

        return back()->withNotify($notify);
    }

    public function login($id)
    {
        $advertiser = Advertiser::findOrFail($id);
        if(auth()->guard('publisher')->check())
        {
            auth()->guard('publisher')->logout();
        }
        Auth::guard('advertiser')->login($advertiser);
        return to_route('advertiser.dashboard');
    }

    public function status(Request $request, $id)
    {
        $advertiser = Advertiser::findOrFail($id);
        if ($advertiser->status == Status::ADVERTISER_ACTIVE) {
            $request->validate([
                'reason' => 'required|string|max:255'
            ]);
            $advertiser->status = Status::ADVERTISER_BAN;
            $advertiser->ban_reason = $request->reason;
            $notify[] = ['success', 'Advertiser banned successfully'];
        } else {
            $advertiser->status = Status::ADVERTISER_ACTIVE;
            $advertiser->ban_reason = null;
            $notify[] = ['success', 'Advertiser unbanned successfully'];
        }
        $advertiser->save();
        return back()->withNotify($notify);
    }

    public function showNotificationSingleForm($id)
    {
        $advertiser = Advertiser::findOrFail($id);
        if (!gs('en') && !gs('sn') && !gs('pn')) {
            $notify[] = ['warning','Notification options are disabled currently'];
            return to_route('admin.advertiser.detail',$advertiser->id)->withNotify($notify);
        }
        $pageTitle = 'Send Notification to ' . $advertiser->username;
        return view('admin.advertisers.notification_single', compact('pageTitle', 'advertiser'));
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

        return NotificationSender::for('advertiser')->notificationToSingle($request, $id);
    }

    public function showNotificationAllForm()
    {
        if (!gs('en') && !gs('sn') && !gs('pn')) {
            $notify[] = ['warning', 'Notification options are disabled currently'];
            return to_route('admin.dashboard')->withNotify($notify);
        }

        $notifyToAdvertiser = Advertiser::notifyToAdvertiser();
        $advertisers        = Advertiser::active()->count();
        $pageTitle    = 'Notification to Verified Advertisers';

        if (session()->has('SEND_NOTIFICATION') && !request()->continue) {
            session()->forget('SEND_NOTIFICATION');
        }

        return view('admin.advertisers.notification_all', compact('pageTitle', 'advertisers', 'notifyToAdvertiser'));
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
            'number_of_top_deposited_advertiser' => 'required_if:being_sent_to,topDepositedAdvertisers|integer|gte:0',
            'number_of_days'               => 'required_if:being_sent_to,notLoginAdvertisers|integer|gte:0',
            'image'                        => ["nullable", 'image', new FileTypeValidate(['jpg', 'jpeg', 'png'])],
        ], [
            'number_of_days.required_if'               => "Number of days field is required",
            'number_of_top_deposited_advertiser.required_if' => "Number of top deposited advertiser field is required",
        ]);

        if (!gs('en') && !gs('sn') && !gs('pn')) {
            $notify[] = ['warning', 'Notification options are disabled currently'];
            return to_route('admin.dashboard')->withNotify($notify);
        }

        return NotificationSender::for('advertiser')->notificationToAll($request);
    }

    public function countBySegment($methodName){
        return Advertiser::active()->$methodName()->count();
    }

    public function list()
    {
        $query = Advertiser::active();

        if (request()->search) {
            $query->where(function ($q) {
                $q->where('email', 'like', '%' . request()->search . '%')->orWhere('username', 'like', '%' . request()->search . '%');
            });
        }
        $advertisers = $query->orderBy('id', 'desc')->paginate(getPaginate());
        return response()->json([
            'success' => true,
            'advertisers'   => $advertisers,
            'more'    => $advertisers->hasMorePages()
        ]);
    }

    public function notificationLog($id)
    {
        $advertiser = Advertiser::findOrFail($id);
        $pageTitle = 'Notifications Sent to ' . $advertiser->username;
        $logs = NotificationLog::where('advertiser_id', $id)->with('advertiser')->orderBy('id', 'desc')->paginate(getPaginate());
        return view('admin.reports.notification_history', compact('pageTitle', 'logs', 'advertiser'));
    }
}
