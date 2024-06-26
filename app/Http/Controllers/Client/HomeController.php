<?php

namespace App\Http\Controllers\Client;

use App\Functions\WhatsApp;
use App\Http\Controllers\BasicController;
use App\Http\Requests\Client\ProfileRequest;
use App\Mail\OrderSummary;
use App\Models\Cart;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Modules\Ad\Entities\Model as Ad;
use Modules\Address\Entities\Model as Address;
use Modules\Client\Entities\Model as Client;
use Modules\Contact\Entities\Model as Contact;
use Modules\Brand\Entities\Model as Brand;
use Modules\Country\Entities\Country;
use Modules\Coupon\Entities\Model as Coupon;
use Modules\Product\Entities\Product;
use Modules\Order\Entities\Model as Order;
use Modules\Service\Entities\Model as Service;
use Modules\Category\Entities\Model as Category;
use Modules\Slider\Entities\Model as Slider;

class HomeController extends BasicController
{

    /*** Go to main page ***/
    public function home()
    {
        $Ads = Ad::Active()->get();
        $Sliders = Slider::Active()->get();

        return view('Client.mainPage', compact('Sliders', 'Ads'));
    }


    public function product($product_id,$color_id = null)
    {
        $Product = Product::where('id', $product_id)->with(['Specs', 'Accessories', 'Categories', 'Features', 'Gallery'])->firstorfail();

        if($Product->Items->count() && $color_id == NULL){
            return redirect()->route('Client.product',['product_id'=>$product_id,'color_id'=>$Product->Items->whereNotNull('color_id')->first()->color_id]);
        }
        
        $wishlist = DB::table('wishlist')->where('client_id', client_id())->where('product_id', $Product->id)->exists();

        return view('Client.product', compact('Product', 'wishlist'));
    }


    public function report($product_id,$size_id,$color_id,$specification_id)
    {
        $Product = Product::where('id', $product_id)->with(['Categories', 'Gallery','Items'])->firstorfail();
        $SelectedItem = $Product->Items
            ->when($size_id, function ($query) use($size_id) {
                return $query->where('size_id', $size_id);
            })
            ->when($color_id, function ($query) use($color_id) {
                return $query->where('color_id', $color_id);
            })
            ->when($specification_id, function ($query) use($specification_id) {
                return $query->where('id', $specification_id);
            })
            ->first();
        $data = [
            'Product' => $Product,
            'SelectedItem' => $SelectedItem,
            'SelectedColor' => $color_id,
            'SelectedSize' => $size_id,
        ];
        $pdf = \niklasravnsborg\LaravelPdf\Facades\Pdf::loadView('Client.report', $data);
        return $pdf->stream('report.pdf');
    }


    public function categories(Request $request)
    {
        $Category = NULL;
        if(request('category')){        
            $Category = Category::Active()->where('id',request('category'))->first();
        }

        $Products = Product::with(['Gallery', 'Categories'])
            ->when(request('brand_id'), function ($query) {
                return $query->where('brand_id', request('brand_id'));
            })
            ->when(request('categories'), function ($query) {
                return $query->whereHas('Categories', function ($query) {
                    $query->whereIn('categories.id', request('categories'));
                });
            })
            ->when(request('category'), function ($query) {
                return $query->whereHas('Categories', function ($query) {
                    $query->where('categories.id', request('category'));
                });
            })
            ->when(request('max_price'), function ($query) {
                return $query->where('price', '>=', request('max_price'));
            })
            ->when(request('min_price'), function ($query) {
                return $query->where('price', '>=', request('min_price'));
            })
            ->when(request('filter'), function ($query) {
                return $query->HasDiscount();
            })
            ->when(request('search'), function ($query) {
                $searchTerm = request('search');

                return $query->where('title_ar', 'LIKE', "%{$searchTerm}%")->orWhere('title_en', 'LIKE', "%{$searchTerm}%");
            })
            ->paginate(25);

        return view('Client.categories', compact('Products','Category'));
    }


    public function submit($delivery_id, Request $request)
    {

        if (auth('client')->check()) {
            $Client = auth('client')->user();
        } else {
            $Client = Client::where('phone', "%{$request->phone}%")->first();
            if (! $Client) {
                $Client = Client::create([
                    'country_id' => $request->country_id,
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'password' => Hash::make(Str::random(10)),
                ]);
            }
        }

        if ($delivery_id == 1) {
            $branch_id = null;
            if ($request->address_id) {
                $Address = Address::find($request->address_id);
            } else {
                $Address = Address::create([
                    'client_id' => $Client->id,
                    'region_id' => $request->region_id,
                    'block' => $request->block,
                    'road' => $request->road,
                    'building_no' => $request->building_no,
                    'floor_no' => $request->floor_no,
                    'apartment' => $request->apartment,
                    'type' => $request->type,
                    'additional_directions' => $request->additional_directions,
                ]);
            }
        } else {
            $Address = null;
            $branch_id = $request->branch_id;
        }

        $Cart = Cart::where('client_id', client_id())->with('Product', 'Color')->get();
        $sub_total = 0;
        $discount = 0;
        foreach ($Cart as $key => $CartItem) {
            $PriceItem = $CartItem->Product->Items->when($CartItem->item_id, function ($query) use($CartItem) {
                                return $query->where('id', $CartItem->item_id);
                            })->first() ?? $CartItem->Product;
            $sub_total += $PriceItem->CalcPrice() * $CartItem->quantity;
            $discount += ($PriceItem->Price() - $PriceItem->CalcPrice()) * $CartItem->quantity;
        }
        $vat = $sub_total / 100 * setting('vat');
        $delivery_cost = $Address ? $Address->Region()->select('delivery_cost')->value('delivery_cost') : 0;
        $Order = Order::create([
            'client_id' => $Client->id,
            'delivery_id' => $delivery_id,
            'address_id' => $Address ? $Address->id : null,
            'branch_id' => $branch_id,
            'payment_id' => $request->payment_id,

            'sub_total' => $sub_total,
            'discount' => $discount,
            'discount_percentage' => 0,
            'vat' => $sub_total / 100 * setting('vat'),
            'vat_percentage' => setting('vat'),
            'coupon' => 0,
            'coupon_percentage' => 0,
            'charge_cost' => $delivery_cost,
            'net_total' => $sub_total + $vat + $delivery_cost,

        ]);

        foreach ($Cart as $key => $CartItem) {
            $SelectedItem = $CartItem->Product->Items->when($CartItem->item_id, function ($query) use($CartItem) {
                                return $query->where('id', $CartItem->item_id);
                            })->first() ?? $CartItem->Product;
            $Order->Products()->attach($CartItem->Product->id, [
                'color_id' => $CartItem->color_id > 0 ? $CartItem->color_id : null,
                'price' => $SelectedItem->Calcprice(),
                'quantity' => $CartItem->quantity,
                'total' => $SelectedItem->Calcprice() * $CartItem->quantity,
            ]);
            $CartItem->Product->Items()->when($CartItem->item_id, function ($query) use($CartItem) {
                return $query->where('id', $CartItem->item_id);
            })->decrement('quantity', $CartItem->quantity) ?? Product::where('id', $CartItem->product->id)->decrement('quantity', $CartItem->quantity);
            $CartItem->delete();
        }

        WhatsApp::SendOrder($Order->id);
        try {
            Mail::to(['apps@emcan-group.com', setting('email'), $Client->email])->send(new OrderSummary($Order));
        } catch (\Throwable $th) {

        }
        alert()->success(__('trans.order_added_successfully'));

        return redirect()->route('Client.home');
    }


    public function paymentConfirmation(Request $request)
    {

        session()->put('delivery_id',$request->delivery_id);

        $delivery_id = session()->get('delivery_id');
        $data['delivery'] = Deliveries()->find($delivery_id);

        $data['address'] = Address::query()->where('client_id',client_id())->first();

        $data['carts'] = Cart::where('client_id', client_id())->get();

        if (count($data['carts']) == 0){
            return redirect()->back();
        }


        // to get total price of cart elements without adding (coupon || vat)
        $result = $this->calcSubtotalCart($data['carts']);
        $data['sub_total'] = $result['sub_total'];

        // to get total price of cart elements with adding (coupon || vat)
        $data['total'] = $this->calcTotalCart($data['sub_total']);

        return view('Client.payment',compact('data'));
    }


    public function storeOrder(Request $request)
    {
        $delivery_id = session()->get('delivery_id');

        $address = Address::query()->where('client_id',client_id())->first();

        if ($delivery_id == 1){ // delivery
            $delivery_cost = $address->Region->delivery_cost;
        }else{
            $delivery_cost = 0;
        }


        $carts = Cart::where('client_id', client_id())->get();

        // to get total price of cart elements without adding (coupon || vat)
        $result = $this->calcSubtotalCart($carts);

        $sub_total = $result['sub_total'];

        $discount = $result['discount'];
        $discount_percentage = round((($discount/$sub_total) * 100),2);

        // to get total price of cart elements with adding (coupon || vat) + delivery_cost
        $total = $this->calcTotalCart($sub_total) + ($delivery_cost ?? 0);

        $vat = $sub_total / 100 * setting('vat');


        try {
            DB::beginTransaction();

            $coupon = session()->get('coupon');
            if ($coupon){
                $coupon = Coupon::query()->find($coupon->id);
                $coupon_value = round($sub_total * ($coupon->value/100),2);
                $coupon->increment('uses_count','1');
            }

            $Order = Order::create([
                'client_id' => Client_id(),
                'delivery_id' => $delivery_id,
                'address_id' => $address->id ?? null,
                'payment_id' => $request->payment_id,
                'sub_total' => $sub_total,
                'discount' => $discount,
                'discount_percentage' => $discount_percentage,
                'vat' => $vat,
                'vat_percentage' => setting('vat'),
                'coupon' => $coupon_value ?? 0,
                'coupon_percentage' => $coupon ? $coupon->value : 0 ,
                'charge_cost' => $delivery_cost,
                'net_total' => $total,
                'notes' => $request->notes,
            ]);

            foreach ($carts as $cart) {
                $Order->Products()->attach($cart->product->id, [
                    'price' => $cart->Product->Calcprice(),
                    'quantity' => $cart->quantity,
                    'total' => $cart->Product->Calcprice() * $cart->quantity,
                ]);
                $product = Product::where('id', $cart->product_id)->decrement('quantity', $cart->quantity);
                $cart->delete();
            }

            WhatsApp::SendOrder($Order->id);
            try {
                Mail::to(['apps@emcan-group.com', setting('email'), auth('client')->user()->email])->send(new OrderSummary($Order));
            } catch (\Throwable $th) {

            }

            DB::commit();
        } catch (\Exception $e) {

            DB::rollback();
            Log::error('Database transaction failed: ' . $e->getMessage());

            // Optionally, you can also return a response with an error message
            return response()->json(['error' => 'An error occurred while processing your request'], 500);
        }


        session()->flash('toast_message', ['type' => 'success', 'message' => __('trans.order_added_successfully')]);

        return redirect()->route('Client.home');
    }


    public function confirm(Request $request)
    {
        $Cart = Cart::where('client_id', client_id())->with('Product', 'Color')->get();

        return view('Client.confirm', compact('Cart'));
    }


    public function cart()
    {
        $Cart = Cart::where('client_id', client_id())->with('Product', 'Color')->get();
        return view('Client.cart', compact('Cart'));
    }


    public function deleteitem()
    {
        Cart::where('client_id', client_id())->where('id', request('id'))->delete();
        $cart_count = Cart::where('client_id', client_id())->count();

        return response()->json([
            'success' => true,
            'type' => 'success',
            'cart_count' => $cart_count,
            'message' => __('trans.DeletedSuccessfully'),
        ]);
    }


    public function minus()
    {
        if (request('count')) {
            Cart::where('client_id', client_id())->where('id', request('id'))->update(['quantity' => request('count')]);
            $cart_count = Cart::where('client_id', client_id())->count();

            return response()->json([
                'success' => true,
                'type' => 'success',
                'cart_count' => $cart_count,
                'message' => __('trans.updatedSuccessfully'),
            ]);
        } else {
            $cart_count = Cart::where('client_id', client_id())->count();

            return response()->json([
                'success' => false,
                'type' => 'error',
                'cart_count' => $cart_count,
                'message' => __('trans.sorry_there_was_an_error'),
            ]);
        }
    }


    public function plus()
    {
        $CartItem = Cart::where('client_id', client_id())->where('id', request('id'))->first();
        $ProductQuantity = Product::where('id', $CartItem->product_id)->select('quantity')->value('quantity');
        if ($ProductQuantity > 0) {
            if ($CartItem->quantity < $ProductQuantity) {
                Cart::where('id', $CartItem->id)->increment('quantity', 1);
            } else {
                return response()->json([
                    'success' => false,
                    'type' => 'error',
                    'message' => __('trans.quantityNotenough'),
                ]);
            }
        } else {
            return response()->json([
                'success' => false,
                'type' => 'error',
                'message' => __('trans.quantityNotenough'),
            ]);
        }

        $cart_count = Cart::where('client_id', client_id())->count();

        return response()->json([
            'success' => true,
            'type' => 'success',
            'cart_count' => $cart_count,
            'message' => __('trans.updatedSuccessfully'),
        ]);
    }


    public function AddToCart(Request $request)
    {
//        return $request;
        $product_id = $request->product_id;
        $quantity = $request->quantity ?? 1;
        $ProductQuantity = Product::where('id', $product_id)->select('quantity')->value('quantity');

        if ($ProductQuantity > 0) {
            $CartItem = Cart::query()->where('client_id', client_id())
                ->where('product_id', $product_id)
                ->where('height_id',$request->height_id)
                ->where('width_id',$request->width_id)
                ->where('sides_closure',$request->sides_closure ? '1' : '0')
                ->where('front_closure',$request->front_closure ? '1' : '0')->first();
            if ($CartItem) {
                if ($CartItem->quantity < $ProductQuantity) {
                        Cart::where('id', $CartItem->id)->increment('quantity', $quantity);
                } else {
                    return response()->json([
                        'success' => false,
                        'type' => 'error',
                        'message' => __('trans.quantityNotenough'),
                    ]);
                }
            } else {
                // Store in cart
                Cart::insert([
                    'client_id' => client_id(),
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'height_id' => $request->height_id,
                    'width_id' => $request->width_id,
                    'sides_closure' => $request->sides_closure ? '1' : '0',
                    'front_closure' => $request->front_closure ? '1' : '0',
                    'notes' => $request->notes,
                ]);

                // increase quantity in Product (product)
//                Product::query()->where('id', $product_id)->decrement('quantity', $quantity);
            }
        } else {
            return Redirect::back()->with([
                'success' => true,
                'type' => 'error',
                'message' => __('trans.quantityNotenough'),
            ]);
        }

//        $cart_count = Cart::where('client_id', client_id())->count();

        session()->flash('toast_message', ['type' => 'success', 'message' => __('trans.addedSuccessfully')]);
        return redirect()->route('Client.continuePurchasingCart');
    }


    /*** continue Buying Cart ***/
    public function continuePurchasingCart()
    {
        $carts = Cart::where('client_id', client_id())->get();

        // to get total price of cart elements without adding (coupon || vat)
        $result = $this->calcSubtotalCart($carts);
        $sub_total = $result['sub_total'];

        // To convert currency
        convertCurrency($sub_total);
        $sub_total = format_number($sub_total);

        return view('Client.cart',compact('carts','sub_total'));
    }


    /*** update product_cart quantity (with ajax) ***/
    public function updateProductCartQuantity(Request $request)
    {
        $cart = Cart::query()->where('client_id', client_id())
            ->where('id',$request->input('cart_id'))->first();

        $cart->update(['quantity'=>$request->input('quantity')]);
        return response()->json([
            'success' => true,
            'quantity' => $cart->quantity,
        ]);
    }


    /*** remove element from cart (with ajax) ***/
    public function removeCartElement(Request $request)
    {
        $cart = Cart::query()->where('client_id', client_id())
            ->where('id',$request->input('cart_id'))->first();

        $cart->delete();

        return response()->json([
            'success' => true,
        ]);
    }


    /*** find coupon (with ajax) ***/
    public function findCoupon(Request $request)
    {
        $coupon_code = $request->input('coupon_code');
        $coupon = Coupon::where('code',$coupon_code)
                        ->whereColumn('uses_count','<','max_uses')
                        ->where('start_date','<=',Carbon::now())
                        ->where('end_date','>=',Carbon::now())
                        ->first();

        if ($coupon) {

            session()->put('coupon',$coupon);

            $coupon_value = $coupon->value;
            $total = $request->input('total_price');
            $total = $total - ($total * ($coupon_value/100));

            return response()->json([
                'success' => true,
                'total' => $total,
                'copCode' => $coupon_code,
                'message' => __('trans.congratulations_valid_coupon')
            ]);
        } else {
            return response()->json([
                'error' => false,
                'message' => __('trans.invalidCoupon')
            ]);
        }
    }


    public function ToggleWishlist(Request $request)
    {
        $product_id = $request->product_id;

        if (DB::table('wishlist')->where('client_id', client_id())->where('product_id', $product_id)->exists()) {
            DB::table('wishlist')->where('client_id', client_id())->where('product_id', $product_id)->delete();

            return response()->json([
                'success' => true,
                'type' => 'success',
                'exists' => 0,
                'message' => __('trans.DeletedSuccessfully'),
            ]);
        } else {
            DB::table('wishlist')->insert([
                'client_id' => client_id(),
                'product_id' => $product_id,
            ]);

            return response()->json([
                'success' => true,
                'type' => 'success',
                'exists' => 1,
                'message' => __('trans.addedSuccessfully'),
            ]);
        }
    }


    public function contact(Request $request)
    {
        Contact::create($request->all());
        toast(__('trans.We Will Contact You as soon as possible'), 'success');

        return back();
    }


    public function getAllCategories(Request $request)
    {
        $categories = Category::Active()->whereHas('Products')->get();
        return view('Client.categories', compact('categories'));
    }


    /*** change ( language, currency, country-region )  ***/
    public function changeWebsiteSettings(Request $request)
    {
        // To change currency and country in (config)
        $country =  Countries()->where('currancy_code_en',$request->currancy_code)->first();
        session()->put('country',$country->id);
        Country($country->id);


        // To change addressCountry in (session)
        $country =  Countries()->where('id',$request->addressCountry_id)->first();
        session()->put('addressCountry', $country);


        // To change addressRegion in (session)
        $region =  regions()->where('id',$request->region_id)->first();
        session()->put('addressRegion', $region);


        // TO update language
        if (isset($request->language) && in_array($request->language, config('app.locales'))) {
            app()->setLocale($request->language);
            session()->put('locale', $request->language);
        }

        return redirect()->back();
    }


    /*** Get regions of country (with ajax) ***/
    public function getRegionsOfCountry(Request $request)
    {
        $countryId = $request->input('country_id');
        $regions = regions()->where('country_id', $countryId)->pluck('title_'.lang(), 'id');
        return response()->json($regions);
    }


    /*** calculate the subtotal of cart used in (continuePurchasingCart, paymentConfirmation) ***/
    public function calcSubtotalCart($carts)
    {
        $sub_total = 0;
        $discount = 0;
        foreach ($carts as $cart){
            if ($cart->Product->HasDiscount()){
                $sub_total += $cart->Product->RealPrice() * $cart->quantity;
                $discount += ($cart->Product->Price() - $cart->Product->RealPrice()) * $cart->quantity;
            }
            else{
                $sub_total += $cart->Product->Price() * $cart->quantity;
            }
        }

        $result = [
            'sub_total' => $sub_total,
            'discount' => $discount
        ];

        return $result;
    }


    /*** calculate the subtotal of cart used in () ***/
    public function calcTotalCart($sub_total)
    {
        $vat = $sub_total / 100 * setting('vat');

        $total = $sub_total;

        $coupon_id = session()->get('coupon_id');
        $coupon = Coupon::query()->find($coupon_id);
        if ($coupon){
            $coupon_value = $coupon->value;
            $total = $sub_total - ($sub_total * ($coupon_value/100));
        }

        return  $total = $vat + $total;
    }

}//end of class
