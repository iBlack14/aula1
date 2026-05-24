<?php

namespace Modules\LMS\Http\Controllers\Frontend;

use Illuminate\Http\Request;
use Modules\LMS\Classes\Cart;
use App\Http\Controllers\Controller;
use Modules\LMS\Repositories\Purchase\PurchaseRepository;
use Modules\LMS\Services\Checkout\CheckoutService;
use Modules\LMS\Services\Payment\PaypalService;
use Modules\LMS\Services\Payment\RazorpayService;

class CheckoutController extends Controller
{

    public function __construct(protected PurchaseRepository $enrolled) {}
    /**checkoutPage
     */
    public function checkoutPage()
    {
        if (!authCheck()) {
            return redirect()->route('login');
        }
        // Check if the cart is empty, and redirect to home if so.
        if (count(Cart::get()) == 0) {
            return redirect()->route('home.index');
        }
        // Prepare cart data for the checkout view.
        $data = [
            'cartCourses' => Cart::get(),
            'totalPrice' => Cart::totalPrice(),
            'discountAmount' => Cart::discountAmount(),
        ];
        return view('theme::checkout.index', compact('data'));
    }
    /**
     * checkout
     */
    public function checkout(Request $request)
    {
        $result = CheckoutService::checkout($request);
        return response()->json($result);
    }

    /**
     * Method transactionSuccess
     *
     * @param  int  $id
     */
    public function transactionSuccess($id = null)
    {
        return view('theme::success.index');
    }
    /**
     *  paymentFormRender
     */
    public function paymentFormRender(Request $request)
    {
        $paymentMethod = $request->payment_method;
        $result = '';
        if ($request->payment_method == "razorpay") {
            $result =  RazorpayService::makePayment();
        }
        if ($request->payment_method == "paypal") {
            $result = PaypalService::makePayment();
        }
        $data = [
            'button' => view('theme::payment.button', compact('paymentMethod', 'result'))->render(),
        ];
        return response()->json([
            'status' => 'success',
            'data' => $data,
            'payment' => true,
        ]);
    }


    /**
     *  courseEnrolled
     */
    public function courseEnrolled(Request $request)
    {
        if (!authCheck()) {
            toastr()->error(translate('Please Login'));
            return redirect()->back();
        }
        $response = $this->enrolled->courseEnrolled($request);

        if ($response['status'] !== "success") {
            return response()->json($response);
        }
        toastr()->success(translate('Thank you for Enrolling'));

        if ($request->ajax()) {
            return response()->json(['status' => $response['status'],  'type' => true]);
        }
        return redirect()->back();
    }
}
