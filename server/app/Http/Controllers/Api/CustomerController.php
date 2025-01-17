<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\BaseController;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Storage;
use app\Exceptions\Handler;
use Illuminate\Support\Facades\Gate;
use App\Models\City;
use App\Models\DistanceCityVN;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client as testClient;

class CustomerController extends BaseController
{
    public function __construct()
    {
        set_time_limit(8000000);
        $this->customer = new Customer();
        $this->distance_city_vn = new DistanceCityVN();
    }

    public function store(Request $request)
    {
        if (!Gate::allows('isAdmin')) {
            return $this->unauthorizedResponse();
        }
        $validated = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'phone' => 'required|unique:customers,phone|regex:/^([0-9\s\-\+\(\)]*)$/|min:10|max:20',
            'password' => 'required|max:255|min:6',
            'sex' => 'required',
            'customer_type' => 'required',
            'avatar' => 'required|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validated->fails()) {
            return $this->failValidator($validated);
        }
        $token = getenv("TWILIO_AUTH_TOKEN");
        $twilio_sid = getenv("TWILIO_SID");
        $twilio_verify_sid = getenv("TWILIO_VERIFY_SID");
        $twilio = new Client($twilio_sid, $token);
        try {
            $twilio->verify->v2->services($twilio_verify_sid)
                ->verifications
                ->create($request['phone'], "sms");
        }
        catch (\Exception $e) {
            return $this->sendError('invalid phone number');
        }

        if ($request->hasFile('avatar')) {
            $feature_image_name= $request['avatar']->getClientOriginalName();
            $path = $request->file('avatar')->storeAs('public/photos/customer', $feature_image_name);
            $linkAvatar = url('/') . Storage::url($path);
            $customer = $this->customer->firstOrCreate([
                'name' => $request['name'],
                'phone' => $request['phone'],
                'password' => Hash::make($request['password']),
                'sex' => $request['sex'],
                'customer_type' => $request['customer_type'],
                'avatar' => $linkAvatar,
            ]);
        }

        return $this->withData($customer, 'Create customer profile successfully!', 201);
    }

    public function verifiedPhone(Request $request)
    {
        $data = $request->validate([
            'verification_code' => ['required', 'numeric'],
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:10|max:20',
        ]);
        /* Get credentials from .env */
        $token = getenv("TWILIO_AUTH_TOKEN");
        $twilio_sid = getenv("TWILIO_SID");
        $twilio_verify_sid = getenv("TWILIO_VERIFY_SID");
        $twilio = new Client($twilio_sid, $token);
        try {
            $verification = $twilio->verify->v2->services($twilio_verify_sid)
                ->verificationChecks
                ->create($data['verification_code'], array('to' => $data['phone']));
        }
        catch (\Exception $e) {
            return $this->sendError("The code is incorrect or has been used!");
        }
        if ($verification->valid) {
            $user = tap(Customer::where('phone', $request['phone']))->update([
                'is_verified' => true,
                'phone_verified_at' => Carbon::now(),
            ]);
            /* Authenticate user */
            return $this->withSuccessMessage('Account activated successfully!');
        }
        return $this->sendError('Mã bảo mật chưa đúng!');
    }

    public function index()
    {
        $users = $this->customer->where('is_verified', 1)->get();
        return $this->withData($users, 'List User');
    }

    public function show($id)
    {
        $customer = $this->customer->where('is_verified', 1)->findOrFail($id);
        return $this->withData($customer, 'Customer Detail');
    }

    public function update(Request $request, $id)
    {
        if (!Gate::allows('isAdmin')) {
            return $this->unauthorizedResponse();
        }
        $validated = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'sex' => 'required',
            'customer_type' => 'required',
            'avatar' => 'required|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validated->fails()) {
            return $this->failValidator($validated);
        }
        $customer = $this->customer->findOrFail($id);
        DB::beginTransaction();
        try {
            $customer->update([
                'name' => $request['name'],
                'sex' => $request['sex'],
                'customer_type' => $request['customer_type'],
            ]);
            if ($request->hasFile('avatar')) {
                $feature_image_name= $request['avatar']->getClientOriginalName();
                $path = $request->file('avatar')->storeAs('public/photos/customer', $feature_image_name);
                $linkAvatar = url('/') . Storage::url($path);
                $customer->avatar = $linkAvatar;
                $customer->save();
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Lỗi khi thay đổi thông tin khách hàng');
        }

        return $this->withData($customer, 'Customer has been updated!');
    }

    public function destroy($id)
    {
        if (!Gate::allows('isAdmin')) {
            return $this->unauthorizedResponse();
        }
        $customer = $this->customer->findOrFail($id)->delete();

        return $this->withSuccessMessage('Người dùng đã xoá thành công!');
    }

    public function search(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:10|max:20',
        ]);
        if ($validated->fails()) {
            return $this->failValidator($validated);
        }
        $customer = Customer::where('phone', $request['phone'])->firstOrFail();

        return $this->withData($customer, 'Search done');
    }

    public function changePassword(Request $request, $customerId)
    {
        if (!Gate::allows('isAdmin')) {
            return $this->unauthorizedResponse();
        }
        $validated = Validator::make($request->all(), [
            'password' => 'required|max:255|min:6',
        ]);
        if ($validated->fails()) {
            return $this->failValidator($validated);
        }
        $customer = $this->customer->findOrFail($customerId);
        $customer->update([
            'password' => Hash::make($request['password']),
        ]);

        return $this->withData($customer, 'Password has been updated!');
    }

    // public function getDistance()
    // {
    //     set_time_limit(8000000);
    //     $cityNameStock = array();
    //     $cityName = array();
    //     foreach(City::all() as $key => $city) {
    //         $cityNameStock[$city->city_id] = $city->name;
    //         $cityName[$city->city_id] = $this->convert_name($city->name) . ',' . 'VN';
    //     }
    //     //dd($cityNameStock);
    //     $cityName[2] = "Ha Noi,VN";
    //     $cityName[6] = "Khanh Hoa Province,VN";
    //     $cityName[13] = "Binh Thuan Province,VN";
    //     $cityName[14] = "Bao Loc,VN";
    //     $cityName[31] = "Tinh Vinh Phuc,VN";
    //     $cityName[44] = "Phu Yen Province,VN";
    //     $cityName[59] = "Lai Chau Province,VN";
    //     $url = 'http://www.mapquestapi.com/directions/v2/routematrix?key=ZkioPbkrzHJnvw3K9w01uvVnGQDAElKO';
    //     //send request
    //     //test

    //     // $test = $this->distance_city_vn->create([
    //     //     'from_city_id' => 1,
    //     //     'to_city_id' => 2,
    //     //     'distance' => 34.6
    //     // ]);
    //     for ($i = 1; $i <= 63 ;$i++) {
    //         for ($j = 1 ; $j <= 63 ; $j++) {
    //             $data = [
    //                 "locations" => [
    //                 $cityName[$i],
    //                 $cityName[$j],
    //                 ],
    //                 "options" => [
    //                 "allToAll" => true
    //                 ]
    //             ];

    //             $postdata = json_encode($data);

    //             $ch = curl_init($url);
    //             curl_setopt($ch, CURLOPT_POST, 1);
    //             curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
    //             curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    //             curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    //             $result = curl_exec($ch);
    //             curl_close($ch);
    //             $array = json_decode($result, true);
    //             $distance = $array["distance"][0][1]*1.61;
    //             $test = $this->distance_city_vn->create([
    //                 'from_city_id' => $i,
    //                 'to_city_id' => $j,
    //                 'from_city' => $cityNameStock[$i],
    //                 'to_city' => $cityNameStock[$j],
    //                 'distance' => $distance
    //             ]);
    //         }
    //     }
        // $data = [
        //     "locations" => [
        //     "Ha Noi,VN",
        //     "Dak Lak,VN",
        //     ],
        //     "options" => [
        //     "allToAll" => true
        //     ]
        // ];

        // $postdata = json_encode($data);

        // $ch = curl_init($url);
        // curl_setopt($ch, CURLOPT_POST, 1);
        // curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        // $result = curl_exec($ch);
        // curl_close($ch);
        // $array = json_decode($result, true);
        // $distance = $array["distance"][0][1]*1.61;
    //     dd("thanh công");
    // }

    public function convert_name($str) {
		$str = preg_replace("/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/", 'a', $str);
		$str = preg_replace("/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/", 'e', $str);
		$str = preg_replace("/(ì|í|ị|ỉ|ĩ)/", 'i', $str);
		$str = preg_replace("/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/", 'o', $str);
		$str = preg_replace("/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/", 'u', $str);
		$str = preg_replace("/(ỳ|ý|ỵ|ỷ|ỹ)/", 'y', $str);
		$str = preg_replace("/(đ)/", 'd', $str);
		$str = preg_replace("/(À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ)/", 'A', $str);
		$str = preg_replace("/(È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ)/", 'E', $str);
		$str = preg_replace("/(Ì|Í|Ị|Ỉ|Ĩ)/", 'I', $str);
		$str = preg_replace("/(Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ)/", 'O', $str);
		$str = preg_replace("/(Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ)/", 'U', $str);
		$str = preg_replace("/(Ỳ|Ý|Ỵ|Ỷ|Ỹ)/", 'Y', $str);
		$str = preg_replace("/(Đ)/", 'D', $str);
		$str = preg_replace("/(\“|\”|\‘|\’|\,|\!|\&|\;|\@|\#|\%|\~|\`|\=|\_|\'|\]|\[|\}|\{|\)|\(|\+|\^)/", '-', $str);
		//$str = preg_replace("/( )/", '-', $str);
		return $str;
	}

}
