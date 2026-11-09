<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Custom (Hader): admin management of consignment sellers. */
class SellersApiController extends Controller
{
    public function index(Request $request)
    {
        $sellers = Seller::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByRaw("FIELD(status,'pending','active','suspended','rejected')")->orderByDesc('id')->get();
        $balances = DB::table('seller_ledger')->groupBy('seller_id')->selectRaw('seller_id, SUM(net) as bal')->pluck('bal', 'seller_id');
        $counts = Product::whereNotNull('seller_id')->groupBy('seller_id')->selectRaw('seller_id, COUNT(*) as c')->pluck('c', 'seller_id');
        $sellers->each(function ($s) use ($balances, $counts) {
            $s->balance = round((float) ($balances[$s->id] ?? 0), 2);
            $s->products_count = (int) ($counts[$s->id] ?? 0);
            $s->statement_url = url('seller-statement/' . $s->statement_token);
        });
        return CommonHelper::responseWithData($sellers);
    }

    public function save(Request $request)
    {
        $v = Validator::make($request->all(), [
            'business_name'      => 'required|string|max:191',
            'contact_name'       => 'required|string|max:191',
            'phone'              => 'required|string|max:40',
            'email'              => 'nullable|email',
            'commission_percent' => 'required|numeric|min:0|max:100',
            'status'             => 'required|in:pending,active,rejected,suspended',
        ]);
        if ($v->fails()) {
            return CommonHelper::responseError($v->errors()->first());
        }
        $seller = $request->filled('id') ? Seller::find($request->id) : new Seller(['statement_token' => (string) Str::uuid()]);
        if (!$seller) {
            return CommonHelper::responseError('Seller not found');
        }
        $wasActive = $seller->exists && $seller->status === 'active';
        $seller->fill($request->only(['business_name', 'contact_name', 'phone', 'email', 'city', 'category',
            'commission_percent', 'status', 'payout_details', 'admin_notes']));
        $seller->save();
        // Custom (Lebazone): a seller that is not active has nothing on the shop. Their live products are
        // hidden (marked 'seller_off') and come back by themselves when the seller is active again.
        $isActive = $seller->status === 'active';
        if ($wasActive && !$isActive) {
            Product::where('seller_id', $seller->id)->where('status', 1)->whereNull('seller_review')
                ->update(['status' => 0, 'seller_review' => 'seller_off']);
        } elseif ($isActive) {
            Product::where('seller_id', $seller->id)->where('seller_review', 'seller_off')
                ->update(['status' => 1, 'seller_review' => null]);
        }
        // Custom (Lebazone): a seller's login works only while the seller is active
        if (!empty($seller->admin_id)) DB::table('admins')->where('id', $seller->admin_id)->update(['status' => $seller->status === 'active' ? 1 : 0]);
        return CommonHelper::responseSuccess('Seller saved');
    }

    public function ledger($id)
    {
        $seller = Seller::find($id);
        if (!$seller) {
            return CommonHelper::responseError('Seller not found');
        }
        $rows = DB::table('seller_ledger')->where('seller_id', $id)->orderByDesc('id')->limit(300)->get();
        $products = Product::where('seller_id', $id)->with('translations')->get(['id', 'name', 'seller_review'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => (string) $p->name, 'review' => (string) ($p->seller_review ?? '')]);
        $login = $seller->admin_id ? DB::table('admins')->where('id', $seller->admin_id)->first(['email', 'status']) : null;
        return CommonHelper::responseWithData([
            'balance'  => round($seller->balance(), 2),
            'ledger'   => $rows,
            'products' => $products,
            'login'    => $login ? ['email' => $login->email, 'active' => (int) $login->status === 1] : null,
            'store'    => ['about' => (string) ($seller->store_about ?? ''), 'banner' => $seller->store_banner ? asset('storage/' . $seller->store_banner) : '', 'url' => self::storeUrl((int) $seller->id)],
        ]);
    }

    public function payout(Request $request)
    {
        $v = Validator::make($request->all(), [
            'seller_id' => 'required|exists:sellers,id',
            'amount'    => 'required|numeric|min:0.01',
            'note'      => 'nullable|string|max:180',
        ]);
        if ($v->fails()) {
            return CommonHelper::responseError($v->errors()->first());
        }
        DB::table('seller_ledger')->insert([
            'seller_id' => $request->seller_id, 'type' => 'payout', 'net' => -round((float) $request->amount, 2),
            'note' => $request->input('note') ?: 'Payout', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return CommonHelper::responseSuccess('Payout recorded');
    }

    /** Link (or unlink with seller_id = 0) products to a seller. */
    public function assignProducts(Request $request)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('product_ids')))));
        if (!$ids) {
            return CommonHelper::responseError('Select at least one product');
        }
        $sellerId = (int) $request->input('seller_id');
        if ($sellerId && !Seller::whereKey($sellerId)->exists()) {
            return CommonHelper::responseError('Seller not found');
        }
        Product::whereIn('id', $ids)->update(['seller_id' => $sellerId ?: null]);
        return CommonHelper::responseSuccess($sellerId ? 'Products linked to seller' : 'Products unlinked');
    }

    // ---------------- Custom (Lebazone): seller logins + approving their products ----------------

    /** Create (or change) the seller's panel login. The password is typed by the admin. */
    public function saveLogin(Request $request)
    {
        $seller = Seller::find((int) $request->input('seller_id'));
        if (!$seller) return CommonHelper::responseError('Seller not found');
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return CommonHelper::responseError('Enter a valid email for the login');
        $roleId = (int) DB::table('roles')->where('name', 'Seller')->where('guard_name', 'web')->value('id');
        if (!$roleId) return CommonHelper::responseError('The Seller role is missing - run the update first');
        $taken = DB::table('admins')->where('email', $email)->when($seller->admin_id, fn ($q) => $q->where('id', '!=', $seller->admin_id))->exists();
        if ($taken) return CommonHelper::responseError('This email is already used by another login');
        $admin = $seller->admin_id ? \App\Models\Admin::find($seller->admin_id) : null;
        if (!$admin && strlen($password) < 8) return CommonHelper::responseError('Choose a password of at least 8 characters');
        if ($admin && $password !== '' && strlen($password) < 8) return CommonHelper::responseError('Choose a password of at least 8 characters');
        if (!$admin) {
            $admin = new \App\Models\Admin();
            $admin->created_by = (int) (auth('api')->id() ?? 1);
        }
        $admin->username = mb_substr($seller->business_name ?: $seller->contact_name, 0, 190);
        $admin->email = $email;
        $admin->role_id = $roleId;
        if ($password !== '') $admin->password = bcrypt($password);
        $admin->status = $request->has('active') ? ((int) $request->input('active') ? 1 : 0) : ($seller->status === 'active' ? 1 : 0);
        $admin->save();
        DB::table('sellers')->where('id', $seller->id)->update(['admin_id' => $admin->id]);
        return CommonHelper::responseSuccess($password !== '' ? 'Seller login saved' : 'Seller login updated');
    }

    /** Products sellers added that wait for approval. */
    public function pendingProducts()
    {
        $rows = Product::where('seller_review', 'pending')->with('translations')->orderBy('id')->limit(200)
            ->get(['id', 'name', 'seller_id', 'seller_review'])
            ->map(function ($p) {
                $v = \App\Models\ProductVariant::where('product_id', $p->id)->orderBy('sort_order')->orderBy('id')->first();
                $st = $v ? DB::table('product_variant_store_stocks')->where('product_variant_id', $v->id)->first() : null;
                return ['id' => $p->id, 'name' => (string) $p->name,
                    'seller' => (string) DB::table('sellers')->where('id', $p->seller_id)->value('business_name'),
                    'image' => $v && $v->image ? asset('storage/' . $v->image) : '',
                    'colors' => \App\Models\ProductVariant::where('product_id', $p->id)->count(),
                    'price' => $st ? (float) $st->price : null, 'sale_price' => $st ? (float) $st->discounted_price : null,
                    'stock' => (int) DB::table('product_variant_store_stocks')->whereIn('product_variant_id',
                        \App\Models\ProductVariant::where('product_id', $p->id)->pluck('id'))->sum('available')];
            });
        return CommonHelper::responseWithData($rows);
    }

    /** Custom (Lebazone): delete products that wait for approval (all of them, or the ids given). */
    public function deletePending(Request $request)
    {
        $q = Product::where('seller_review', 'pending');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('product_ids', '')))));
        if ($ids) $q->whereIn('id', $ids);
        $n = 0;
        foreach ($q->get(['id']) as $p) {
            $vids = \App\Models\ProductVariant::where('product_id', $p->id)->pluck('id');
            if ($vids->count() && DB::table('order_items')->whereIn('product_variant_id', $vids)->exists()) continue; // never ordered while hidden, but stay safe
            \App\Models\ProductVariant::whereIn('id', $vids)->delete();
            $p->delete();
            $n++;
        }
        return CommonHelper::responseSuccess($n === 1 ? '1 product deleted' : "$n products deleted");
    }

    /** Approve (goes live) or reject (stays hidden, the seller sees the reason). */
    public function reviewProduct(Request $request)
    {
        $p = Product::find((int) $request->input('product_id'));
        if (!$p || !$p->seller_id) return CommonHelper::responseError('Product not found');
        if ($request->input('decision') === 'approve') {
            $active = Seller::where('id', $p->seller_id)->value('status') === 'active';
            DB::table('products')->where('id', $p->id)->update($active
                ? ['status' => 1, 'seller_review' => null, 'seller_review_note' => null]
                : ['status' => 0, 'seller_review' => 'seller_off', 'seller_review_note' => null]);
            return CommonHelper::responseSuccess($active ? 'Approved - the product is on the shop now'
                : 'Approved - it goes on the shop when the seller is active again');
        }
        DB::table('products')->where('id', $p->id)->update(['status' => 0, 'seller_review' => 'rejected',
            'seller_review_note' => mb_substr(trim((string) $request->input('note')), 0, 250) ?: null]);
        return CommonHelper::responseSuccess('Rejected - the seller sees it in their account');
    }

    /** The logged-in seller's own page: balance, sales, payouts, products. */
    public function portal()
    {
        $u = auth('api')->user();
        $seller = $u ? Seller::where('admin_id', $u->id)->first() : null;
        if (!$seller) return CommonHelper::responseError('Your seller account is not set up yet - contact Lebazone');
        $products = Product::where('seller_id', $seller->id)->with('translations')->orderByDesc('id')->limit(300)
            ->get(['id', 'name', 'status', 'seller_review', 'seller_review_note'])
            ->map(function ($p) {
                $vids = \App\Models\ProductVariant::where('product_id', $p->id)->pluck('id');
                $v = \App\Models\ProductVariant::where('product_id', $p->id)->orderBy('sort_order')->orderBy('id')->first();
                $st = $v ? DB::table('product_variant_store_stocks')->where('product_variant_id', $v->id)->first() : null;
                $state = $p->seller_review === 'pending' ? 'waiting' : ($p->seller_review === 'rejected' ? 'rejected' : ($p->seller_review === 'seller_off' ? 'paused' : ((int) $p->status === 1 ? 'live' : 'hidden')));
                return ['id' => $p->id, 'name' => (string) $p->name, 'state' => $state, 'note' => (string) ($p->seller_review_note ?? ''),
                    'image' => $v && $v->image ? asset('storage/' . $v->image) : '',
                    'price' => $st ? (float) $st->discounted_price : null,
                    'stock' => (int) DB::table('product_variant_store_stocks')->whereIn('product_variant_id', $vids)->sum('available')];
            });
        return CommonHelper::responseWithData([
            'id' => (int) $seller->id,
            'store_about' => (string) ($seller->store_about ?? ''),
            'store_banner' => $seller->store_banner ? asset('storage/' . $seller->store_banner) : '',
            'store_url' => self::storeUrl((int) $seller->id),
            'business_name' => $seller->business_name,
            'commission_percent' => (float) $seller->commission_percent,
            'status' => $seller->status,
            'balance' => round($seller->balance(), 2),
            'ledger' => DB::table('seller_ledger')->where('seller_id', $seller->id)->orderByDesc('id')->limit(100)
                ->get(['id', 'type', 'gross', 'commission', 'net', 'note', 'created_at']),
            'products' => $products,
        ]);
    }

    /** Custom (Lebazone): public store header for the website - name, banner, about. */
    public function publicStore(Request $request)
    {
        $s = Seller::where('id', (int) $request->input('id'))->where('status', 'active')->first();
        if (!$s) return CommonHelper::responseError('Store not found');
        return CommonHelper::responseWithData([
            'id' => (int) $s->id,
            'name' => (string) $s->business_name,
            'city' => (string) ($s->city ?? ''),
            'about' => (string) ($s->store_about ?? ''),
            'banner' => $s->store_banner ? asset('storage/' . $s->store_banner) : '',
            'products' => (int) Product::where('seller_id', $s->id)->where('status', 1)->count(),
        ]);
    }

    /** Custom (Lebazone): the logged-in seller sets their store banner and about text. */
    public function saveStore(Request $request)
    {
        $u = auth('api')->user();
        $seller = $request->filled('seller_id') && !$this->isSellerLogin()
            ? Seller::find((int) $request->input('seller_id'))            // admin editing a seller
            : ($u ? Seller::where('admin_id', $u->id)->first() : null);   // the seller themselves
        if (!$seller) return CommonHelper::responseError('Seller not found');
        $about = trim((string) $request->input('about', ''));
        if (mb_strlen($about) > 600) return CommonHelper::responseError('Keep the text under 600 characters');
        $data = ['store_about' => $about !== '' ? $about : null];
        if ($request->hasFile('banner')) {
            $f = $request->file('banner');
            if (!$f->isValid() || !str_starts_with((string) $f->getMimeType(), 'image/')) return CommonHelper::responseError('The banner must be a picture');
            if ($f->getSize() > 6 * 1024 * 1024) return CommonHelper::responseError('The banner picture is too big (max 6 MB)');
            $data['store_banner'] = $f->store('sellers/banners', 'public');
        } elseif ($request->input('remove_banner') === '1') {
            $data['store_banner'] = null;
        }
        DB::table('sellers')->where('id', $seller->id)->update($data);
        return CommonHelper::responseSuccess('Store page saved');
    }

    /** Custom (Lebazone): the seller's store page on the website. */
    public static function storeUrl(int $sellerId): string
    {
        $site = rtrim((string) \App\Models\Setting::get_value('website_url'), '/');
        if ($site === '' || str_contains($site, 'localhost')) { // admin.lebazone.store -> lebazone.store
            $site = rtrim(preg_replace('#//admin\.#', '//', (string) config('app.url')), '/');
        }
        $zone = (string) (DB::table('zones')->where('status', 1)->orderBy('id')->value('slug') ?: 'lebanon');
        return $site . '/' . $zone . '/products?seller=' . $sellerId;
    }

    private function isSellerLogin(): bool
    {
        $u = auth('api')->user();
        return $u && $u->role && $u->role->name === 'Seller';
    }

    /** Product picker search. */
    public function searchProducts(Request $request)
    {
        $q = trim((string) $request->input('search', ''));
        $rows = Product::query()->with('translations')
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")
                  ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('id')->limit(30)->get(['id', 'name', 'seller_id'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => (string) $p->name, 'seller_id' => $p->seller_id]);
        return CommonHelper::responseWithData($rows);
    }
}
