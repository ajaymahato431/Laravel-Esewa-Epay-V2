<?php

namespace AjayMahato\Esewa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use AjayMahato\Esewa\Facades\Esewa;
use AjayMahato\Esewa\Models\EsewaPayment;
use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Events\EsewaPaymentVerified;
use AjayMahato\Esewa\Exceptions\EsewaException;

class CallbackController extends Controller
{
    public function handle(Request $request)
    {
        $base64 = $request->input('data'); // eSewa sends Base64 body

        try {
            $verified = Esewa::verifyCallback($base64);

            $payment = EsewaPayment::query()
                ->where('transaction_uuid', $verified['transaction_uuid'])
                ->first();

            if (!$payment) {
                $payment = EsewaPayment::create([
                    'transaction_uuid' => $verified['transaction_uuid'],
                    'product_code'     => $verified['product_code'],
                    'amount'           => (int)($verified['total_amount'] ?? 0),
                    'total_amount'     => (int)($verified['total_amount'] ?? 0),
                    'tax_amount'       => 0,
                    'service_charge'   => 0,
                    'delivery_charge'  => 0,
                    'status'           => PaymentStatus::from($verified['status'] ?? 'PENDING'),
                ]);
            }

            $payment->update([
                'status'       => PaymentStatus::from($verified['status']),
                'ref_id'       => $verified['transaction_code'] ?? null,
                'verified_at'  => Carbon::now(),
                'raw_response' => $verified,
            ]);

            event(new EsewaPaymentVerified($payment));

            // return JSON or redirect to a pretty page:
            return response()->json(['ok' => true, 'data' => $verified]);
        } catch (EsewaException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
