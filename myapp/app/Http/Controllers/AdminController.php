<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use Entrust;
use Session;
use Auth;
use App\Role;
use App\DonationHelp;
use App\Http\Helpers\ApplicationHelpers;
use App\Http\Helpers\MyCustomException; 
use App\Http\Requests\MessagingRequest;
use App\Messaging;
use App\MessagingTransaction;
use App\DonationTransaction;
use App\Analytic;

class AdminController extends Controller
{
	public function __construct() {
		$this->middleware(['auth', 'customChecks', 'role:admin|superadmin']);
	}
    public function viewUsers() {
    	$users = User::latest()->paginate(20);
    	$users_count = User::get()->count();
    	$active_count = User::where('isBlocked', 0)->count();
    	$inactive_count = User::where('isBlocked', 1)->count();
    	//get the roles
    	$roles = array();
    	$roles_maps = Role::pluck('display_name', 'id')->toArray();
    	foreach($roles_maps as $id => $value) {
    		if(strtolower($value) == 'super admin') {
    			continue;
    		}
    		$roles[$id] = $value;
    	}
    	return view('admin/users/view', compact(
            'users', 'roles', 'users_count', 'active_count', 'inactive_count'));
    }
    public function blockUser(Request $request, $user_id) {
    	$user = User::findOrFail($user_id);
    	$user->isBlocked = 1;
    	$user->points = 0;
    	$user->save();
    	Session::flash('flash_message', 'User Blocked was Successful');
    	return redirect()->back();
    }
    public function unblockUser(Request $request, $user_id) {
    	$user = User::findOrFail($user_id);
    	$user->isBlocked = 0;
    	$user->points = 20;
    	$user->save();
    	Session::flash('flash_message', 'User Unblocked was Successful');
    	return redirect()->back();
    }
    public function viewUserProfile(Request $request, $user_id) {
        $user = User::findOrFail($user_id);        
    	return view('admin/users/show',compact('user'));
    } 
    public function storeChangedUserPassword(Request $request, $user_id) {
        //only admin can perform this operation
        $inputs = $request->all();
        $validator = $this->validate($request, [
            'password' => 'required|min:6',
            'confirmPassword' => 'required|min:6|same:password',
        ]);
        if($validator !== null) {
            return redirect()->back();
        }
        //everything is ok save the password profile
        $user = User::findOrFail($user_id);
        $user->password = bcrypt($inputs['password']);
        $user->save();
        //if successful redirect
        Session::flash('flash_message', 'Password change successful');
        return redirect()->back();
    }
    public function changeRoles(Request $request){
    	$inputs = $request->all();
    	//validate
    	$validator = $this->validate($request, array(
    		'change_role' => 'required',
    		'user_ids' => 'required'));
    	if($validator !== null) {
    		return redirect()->back()->withErrors($validator)->withInput();
    	}
    	//get the desired role
    	$role = Role::findOrFail($inputs['change_role']);
    	$users = User::findOrFail($inputs['user_ids']);
    	foreach ($users as $user) {
			$user->detachRoles();
			$user->attachRole($role->id);
    	}
    	Session::flash('flash_message', 'Roles Changed Successfully');
    	return redirect()->back();
    }
    public function viewPhOrders() {
    	$donations = DonationHelp::where('phGh', 'ph')->latest()->paginate(50);
    	//count the pending orders
    	$pending_count = DonationHelp::where(['phGh' => 'ph'
    		, 'status' => DonationHelp::$SLIP_PENDING])->count();
    	//count the confirmed orders
    	$confirmed_count = DonationHelp::where(['phGh' => 'ph'
			, 'status' => DonationHelp::$SLIP_CONFIRMED])->count();
    	// count the ellapsed orders
    	$elapsed_count = DonationHelp::where(['phGh' => 'ph'
    		, 'status' => DonationHelp::$SLIP_ELAPSED])->count();

    	$pending_amount = DonationHelp::where(['phGh' => 'ph'
    		, 'status' => DonationHelp::$SLIP_PENDING])->pluck('amount')->toArray();
    	    	    	
    	$confirmed_amount = DonationHelp::where(['phGh' => 'ph'
    		, 'status' => DonationHelp::$SLIP_CONFIRMED])->pluck('amount')->toArray();

    	$pending_amount = array_sum($pending_amount);
    	$confirmed_amount = array_sum($confirmed_amount);

    	return view('admin.ph', compact('donations','pending_count'
    		, 'confirmed_count', 'elapsed_count', 'pending_amount', 'confirmed_amount'));
    }
    public function viewGhOrders() {
    	$collections = DonationHelp::where('phGh', 'gh')->latest()->paginate(50);
    	//count the confirmed request 
    	$confirmed_count = DonationHelp::where(['phGh' => 'gh'
    		, 'status' => DonationHelp::$SLIP_CONFIRMED])->count();
    	//count the pending request 
    	$pending_count = DonationHelp::where(['phGh' => 'gh'
    		, 'status' => DonationHelp::$SLIP_PENDING])->count();
    	//count the withdrawn request
    	$payout_count = DonationHelp::where(['phGh' => 'gh'
    		, 'status' => DonationHelp::$SLIP_WITHDRAWAL])->count();

    	    	
    	$pending_amount = DonationHelp::where(['phGh' => 'gh'
    		, 'status' => DonationHelp::$SLIP_PENDING])->pluck('amount')->toArray();
    	    	    	
    	$confirmed_amount = DonationHelp::where(['phGh' => 'gh'
    		, 'status' => DonationHelp::$SLIP_CONFIRMED])->pluck('amount')->toArray();

    	$pending_amount = array_sum($pending_amount);
    	$confirmed_amount = array_sum($confirmed_amount);

    	return view('admin.gh', compact('collections', 'confirmed_count'
    		, 'pending_count', 'payout_count', 'pending_amount', 'confirmed_amount'));
    }
   
    public function matchGHRequest(Request $request) {
        $this->matchGHByBankRequest($request);
        $this->matchGHByBitcoinRequest($request);
        $this->rematchGHBitcoinVictimsOfFakePOP($request);
        $this->rematchGHBankVictimsOfFakePOP($request);

        
    }
    public function matchGHByBankRequest($request) {
        //get all the pending gh and also based on date TODO
        $ghs = DonationHelp::where(['phGh'=>'gh', 'status'=>DonationHelp::$SLIP_PENDING])
            ->where('paymentType', 'bank')
            ->get()->toArray();
        // $ghs = DonationHelp::where(['phGh'=>'gh', 'status'=>DonationHelp::$SLIP_PENDING])->get()->toArray();
        //get all the phs
        $phs = DonationHelp::where(['phGh'=>'ph', 'status'=>DonationHelp::$SLIP_PENDING])
            ->whereRaw('created_at <= DATE_SUB(curdate(), INTERVAL 3 WEEK) ')
            ->where('paymentType', 'bank')->get()->toArray();
        //get all the userss
        $users = User::pluck('name', 'id')->toArray();
        //do exact match
        list($ghs, $phs) = ApplicationHelpers::doExactMatch($ghs, $phs, $users);
        list($ghs, $phs) = ApplicationHelpers::matchOneGHToTwoPH($ghs, $phs, $users);
        list($ghs, $phs) = ApplicationHelpers::matchOnePHToTwoGH($ghs, $phs, $users);
        list($ghs, $phs) = ApplicationHelpers::matchOneGHToThreePH($ghs, $phs, $users);
        list($ghs, $phs) = ApplicationHelpers::matchOnePHToThreeGH($ghs, $phs, $users);

        //if no match exists set the matchcount to one
        if(count($ghs) > 0) {
            foreach ($ghs as $gh) {
                $update_gh = DonationHelp::findOrFail($gh['id']);
                $update_gh->matchCounter = $update_gh->matchCounter + 1;
                $update_gh->save();
                // echo "Saving ".$update_gh->user->name." with amount ".$update_gh->amount."....<br />";
            }
        }
        if(count($phs) > 0) {
            foreach ($phs as $ph) {
                $update_ph = DonationHelp::findOrFail($ph['id']);
                $update_ph->matchCounter = $update_ph->matchCounter + 1;
                $update_ph->save();
                // echo "Saving ".$update_ph->user->name." with amount ".$update_ph->amount."....<br />";
            }
        }
    }
    public function matchGHByBitcoinRequest($request) {
        //get all the pending gh and also based on date TODO
        $ghs = DonationHelp::where(['phGh'=>'gh', 'status'=>DonationHelp::$SLIP_PENDING])
            ->where('paymentType', 'bitcoin')->get()->toArray();
        // $ghs = DonationHelp::where(['phGh'=>'gh', 'status'=>DonationHelp::$SLIP_PENDING])->get()->toArray();
        //get all the phs
        $phs = DonationHelp::where(['phGh'=>'ph', 'status'=>DonationHelp::$SLIP_PENDING])
            ->where('paymentType', 'bitcoin')
            ->whereRaw('created_at <= DATE_SUB(curdate(), INTERVAL 3 WEEK) ')
            ->get()->toArray();
        //get all the userss
        $users = User::pluck('name', 'id')->toArray();
        //do exact match
        list($ghs, $phs) = ApplicationHelpers::doExactMatch($ghs, $phs, $users);
        list($ghs, $phs) = ApplicationHelpers::matchOneGHToTwoPH($ghs, $phs, $users);
        list($ghs, $phs) = ApplicationHelpers::matchOnePHToTwoGH($ghs, $phs, $users);
        list($ghs, $phs) = ApplicationHelpers::matchOneGHToThreePH($ghs, $phs, $users);
        list($ghs, $phs) = ApplicationHelpers::matchOnePHToThreeGH($ghs, $phs, $users);

        //if no match exists set the matchcount to one
        if(count($ghs) > 0) {
            foreach ($ghs as $gh) {
                $update_gh = DonationHelp::findOrFail($gh['id']);
                $update_gh->matchCounter = $update_gh->matchCounter + 1;
                $update_gh->save();
                // echo "Saving ".$update_gh->user->name." with amount ".$update_gh->amount."....<br />";
            }
        }
        if(count($phs) > 0) {
            foreach ($phs as $ph) {
                $update_ph = DonationHelp::findOrFail($ph['id']);
                $update_ph->matchCounter = $update_ph->matchCounter + 1;
                $update_ph->save();
                // echo "Saving ".$update_ph->user->name." with amount ".$update_ph->amount."....<br />";
            }
        }
    }

    public function compose() {
        return view('admin/messaging/compose');
    }
    public function sendMessage(MessagingRequest $request) {
        $user = Auth::User();
        $inputs = $request->all();
        //get all the users
        $users = User::where('isBlocked', 0)->pluck('id');
        $messaging = Messaging::create($inputs);
        //get the transactions
        foreach($users as $id) {
            if($id == $user->id) {
                continue;
            }
            $transaction = new MessagingTransaction();
            $transaction->messagingID = $messaging->id;
            $transaction->userID = $id;
            $transaction->messageFlag = 'received';
            $transaction->save();
        }
        $transaction = new MessagingTransaction();
        $transaction->messagingID = $messaging->id;
        $transaction->userID = $user->id;
        $transaction->messageFlag = 'sent';
        $transaction->save();

        Session::flash('flash_message', 'Message Sent successfully');
        return redirect()->back();
    }
    public function replyMessage(Request $request) {
       $validator = $this->validate($request, array(
            'body' => 'required|min:3'
        ));
       if($validator !==null) {
            return redirect()->back()->withErrors($validator)->withInput();
       }
       $user = Auth::User();
        $inputs = $request->all();
        $subject = (substr($inputs['subject'], 0, 3) == 'RE:') ? $inputs['subject'] : 'RE:'.$inputs['subject'];
        //get all the users
        $messaging = new Messaging();
        $messaging->body = $inputs['body'];
        $messaging->subject = $subject;
        $messaging->save();
        //get the transactions
        $transaction = new MessagingTransaction();
        $transaction->messagingID = $messaging->id;
        $transaction->userID = $inputs['recipient_id'];
        $transaction->messageFlag = 'received';
        $transaction->save();

        $transaction = new MessagingTransaction();
        $transaction->messagingID = $messaging->id;
        $transaction->userID = $user->id;
        $transaction->messageFlag = 'sent';
        $transaction->save();

        Session::flash('flash_message', 'Message replied successfully');
        return redirect()->back();
    }
    public function inbox() {
        $user = Auth::User();
        $messages = MessagingTransaction::where('userID', $user->id)
            ->where('messageFlag', 'received')->latest()->paginate(50);
        return view('admin/messaging/inbox', compact('messages'));
    }
    public function outbox() {
        $user = Auth::User();
        $messages = MessagingTransaction::where('userID', $user->id)
            ->where('messageFlag', 'sent')->latest()->paginate(50);
        return view('admin/messaging/outbox', compact('messages'));
    }
    public function showMessage(Request $request, $trans_id) {
        $user = Auth::User();
        $message = MessagingTransaction::where('id', $trans_id)
            ->where('userID', $user->id)->first();
        $message->readStatus = 1;
        $message->save();
        $sender = MessagingTransaction::where('messageFlag', 'sent')
            ->where('messagingID', $message->messagingID)->first();
        return view('admin/messaging/details', compact('message', 'sender'));   
    }

    public function matchPartialWithdrawalGHRequest() {
        //TODO
         //get all the pending gh and also based on date TODO
        $ghs = DonationHelp::where(['phGh'=>'gh', 'status'=>DonationHelp::$SLIP_WITHDRAWAL])
            ->get()->toArray();
        // $ghs = DonationHelp::where(['phGh'=>'gh', 'status'=>DonationHelp::$SLIP_PENDING])->get()->toArray();
        //get all the phs
        $phs = DonationHelp::where(['phGh'=>'ph', 'status'=>DonationHelp::$SLIP_PENDING])
            ->whereRaw('created_at <= DATE_ADD(curdate(), INTERVAL 3 WEEK) ')->get()->toArray();
    }
    public function fakepop() {
        //get all the transactions
        $transactions = DonationTransaction::where('fakePOP', 1)->get();
        return view('admin/users/fakepop', compact('transactions'));
    }
    public function blockDonorAndDeleteTrans(Request $request, $trans_id) {
        //get the trans
        $transaction = DonationTransaction::findOrFail($trans_id);
        if($transaction){
            //block donor
            $transaction->donation->user->isBlocked = 1;
            $transaction->donation->save();
            //delete the transaction
            $transaction->delete();
            Session::flash('flash_message', 'User was Succesfully blocked');
            return redirect()->back();
        }
    }
    public function adminGHRequestStore(Request $request, $amount) {
        $user = Auth::User();
        if(!$user->hasRole('superadmin')) {
            return redirect()->back()->withErrors('This is only available
             as a super admin') ;
        }

        $phs = DonationHelp::where(['phGh'=>'ph', 'status'=>DonationHelp::$SLIP_PENDING])
            ->whereRaw('created_at <= DATE_SUB(curdate(), INTERVAL 3 WEEK) ')
            ->where('paymentType', 'bank')->get()->toArray();
        //get all the userss
        $users = User::pluck('name', 'id')->toArray();
        $phs = ApplicationHelpers::matchAdminToPH($user, $phs, $users, $amount);
    }
    public function rematchGHBankVictimsOfFakePOP(Request $request) {
        $phs = DonationHelp::where(['phGh'=>'ph', 'status'=>DonationHelp::$SLIP_PENDING])
            ->whereRaw('created_at <= DATE_SUB(curdate(), INTERVAL 3 WEEK)')
            ->where('paymentType', 'bank')->get()->toArray();

        //get the transactions
        $transactions = DonationTransaction::where('fakePOP', 1)->onlyTrashed()->get();
        if(!$transactions) {
            echo 'No fake pop at the moment';
        }
        //get all the users
        $users = User::pluck('name', 'id')->toArray();
        $phs = ApplicationHelpers::matchTransactionToOnePH($phs, $users, $transactions, 'bank');
        $phs = ApplicationHelpers::matchTransactionToTwoPH($phs, $users, $transactions, 'bank');
        $phs = ApplicationHelpers::matchTransactionToThreePH($phs, $users, $transactions, 'bank');

    }
    public function rematchGHBitcoinVictimsOfFakePOP(Request $request) {
        $phs = DonationHelp::where(['phGh'=>'ph', 'status'=>DonationHelp::$SLIP_PENDING])
            ->whereRaw('created_at <= DATE_SUB(curdate(), INTERVAL 3 WEEK)')
            ->where('paymentType', 'bitcoin')->get()->toArray();

        //get the transactions
        $transactions = DonationTransaction::where('fakePOP', 1)->onlyTrashed()->get();
        if(!$transactions) {
            echo 'No fake pop at the moment';
        }
        //get all the users
        $users = User::pluck('name', 'id')->toArray();
        $phs = ApplicationHelpers::matchTransactionToOnePH($phs, $users, $transactions, 'bitcoin');
        $phs = ApplicationHelpers::matchTransactionToTwoPH($phs, $users, $transactions, 'bitcoin');
        $phs = ApplicationHelpers::matchTransactionToThreePH($phs, $users, $transactions, 'bitcoin');

    }
    public function analytics(){
        $analytics = Analytic::latest()->paginate('30');
        return view('admin.analytics', compact('analytics'));
    }
}
