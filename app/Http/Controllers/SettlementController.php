<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Log;
use Stripe\Stripe;
use App\Item;
use App\Address;
use App\Cart;
use App\User;
use App\Settlement;
use App\Purchase;
use Validator;

class SettlementController extends Controller
{
	//
	public function index(Request $request) {
		$registered_address = Address::with('prefecture')->where('user_id', Auth::id())->get();
		$items_in_carts = Cart::with('item')->where('customer_id', Auth::id())->get();
		$total_price = 0;
		foreach ($items_in_carts as $cart) {
			$total_price += $cart->item->price * $cart->item_amount;
		}

		//合計金額をセッションに登録
		$request->session()->put('session_price',$total_price);
		return view('settlement.index', compact('registered_address', 'items_in_carts', 'total_price'));
	}

	public function payment(Request $request) {

		//住所の選択が1つ以外の場合リダイレクト
		if (count($request->deliver_address) != 1) {
			session()->flash('flash_message', '住所は一つ選択してください');
			return redirect(url()->previous());
		}

		//チェックボックスのidを操作された場合リダイレクト
		if (!Address::where('user_id', Auth::id())->where('id', $request->deliver_address[0])->exists()) {
			session()->flash('flash_message', '住所を選択してください');
			return redirect(url()->previous());
		}

		//セッションに登録した決済金額取得
		$session_price = $request->session()->get('session_price');
		$items_in_carts = Cart::with('item')->where('customer_id', Auth::id())->get();

		$price = 0;
		foreach ($items_in_carts as $cart) {
			$price += $cart->item->price * $cart->item_amount;
		}

		//セッションで受けた金額と決済時に取得した金額が違う場合リダイレクト
		if ($price != $session_price) {
			session()->flash('flash_message', 'もう一度やり直してください');
			return redirect(url()->previous());
		}

		//カート内商品を削除
		if ($price < 50) {
			session()->flash('flash_message', '50円以下の決済はできません');
			return redirect(url()->previous());
		}

		//シークレットキーをセット
		try {
			\Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
			$stripe_token = $request->stripeToken;
			$charge = \Stripe\Charge::create([
				//合計金額
				'amount' => $price,
				//通過
				'currency' => 'JPY',
				//一意のトークン
				'source' => $stripe_token,
			]);
			session()->flash('flash_message', '決済が完了しました');
		} catch (\Stripe\Exception\CardException $e) {
			//エラー内容を取得して、ログに表示
			$body = $e->getJsonBody();
			Log::debug($body);
			session()->flash('flash_message', '決済ができませんでした。');
			return redirect(url()->previous());
		}
		//登録者住所取得
		$deliver_info = Address::where('user_id', Auth::id())->where('id', $request->deliver_address[0])->first();

		//決済のステータスコードを取得
		if ($charge->status == "succeeded") {
			$status_code = 1;
		}

		//カート内のアイテムを削除
		Cart::where('customer_id', Auth::id())->delete();

		//購入品テーブルにデータを挿入
		foreach ($items_in_carts as $cart) {
			$purchase = new Purchase();
			$purchase->user_id = Auth::id();
			$purchase->item_id = $cart->item_id;
			$purchase->item_amount = $cart->item_amount;
			$purchase->settlement_id = Settlement::latest()->first()->id + 1;
			$purchase->save();
		}

		//ユーザー情報更新
		User::updateOrCreate(
			['id' => Auth::id()],
			['stripe_id' => $charge->id,
			'card_brand' => $charge->source->brand,
			'card_last_four' => $charge->source->last4
		]
	);

		//決済テーブルへデータをインサート
		$settlement = new Settlement();
		$settlement->user_id = Auth::id();
		$settlement->stripe_id = $charge->id;
		$settlement->name = $deliver_info->customer_name;
		$settlement->postal_code = $deliver_info->postal_code;
		$settlement->prefecture_id = $deliver_info->prefecture_id;
		$settlement->city = $deliver_info->city;
		$settlement->phone_number = $deliver_info->phone_number;
		$settlement->amount = $price;
		$settlement->failure_code = $charge->failure_code;
		$settlement->failure_message = $charge->failure_message;
		$settlement->status_code = $status_code;
		//今後クーポン設定をする場合設定
		//$settlement->ends_at = ?;
		$settlement->save();

		//確認画面へ行く
		return redirect(url()->previous());
	}
}
