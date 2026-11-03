<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Services\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AttributeApiController extends Controller
{
    protected $languageService;

    public function __construct(LanguageService $languageService)
    {
        $this->languageService = $languageService;
    }

    public function usage(Request $request)
    {
        $attributeId = (int) $request->input('id', $request->input('attribute_id', 0));
        if (!$attributeId) {
            return CommonHelper::responseWithData(['product_count' => 0, 'variant_count' => 0, 'values' => []]);
        }

        $base = DB::table('product_variant_attribute_values as pvav')
            ->join('product_variants as pv', 'pv.id', '=', 'pvav.product_variant_id')
            ->where('pvav.attribute_id', $attributeId);

        $productCount = (clone $base)->distinct()->count('pv.product_id');
        $variantCount = (clone $base)->distinct()->count('pvav.product_variant_id');

        $perValue = (clone $base)
            ->select('pvav.attribute_value_id', DB::raw('COUNT(DISTINCT pv.product_id) as c'))
            ->groupBy('pvav.attribute_value_id')
            ->pluck('c', 'pvav.attribute_value_id');

        return CommonHelper::responseWithData([
            'product_count' => (int) $productCount,
            'variant_count' => (int) $variantCount,
            'values'        => $perValue,
        ]);
    }

    public function list(Request $request)
    {
        if ($request->filled('id')) {
            $attribute = Attribute::withAllTranslations()
                ->with(['values' => function ($q) {
                    $q->withAllTranslations();
                }])
                ->where('id', $request->id)
                ->first();

            return CommonHelper::responseWithData($attribute);
        }

        $limit = (int) $request->input('per_page', 10);
        $page = max((int) $request->input('page', 1), 1);
        $offset = ($page - 1) * $limit;
        $filter = $request->input('filter', '');

        $query = Attribute::withAllTranslations()
            ->with(['values' => function ($q) {
                $q->withAllTranslations();
            }])
            ->orderBy('id', 'DESC');

        if ($filter) {
            $query->where(function ($q) use ($filter) {
                $q->where('id', 'like', "%{$filter}%")
                    ->orWhere('name', 'like', "%{$filter}%")
                    ->orWhere('slug', 'like', "%{$filter}%");
            });
        }

        $total = $query->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return CommonHelper::responseWithData($rows, $total);
    }

    /**
     * Lightweight list for selectors (category form).
     */
    public function dropdown(Request $request)
    {
        $rows = Attribute::withAllTranslations()
            ->with(['activeValues' => function ($q) {
                $q->withAllTranslations();
            }])
            ->where('status', 1)
            ->orderBy('id')
            ->get();

        return CommonHelper::responseWithData($rows);
    }

    public function save(Request $request)
    {
        $defaultLanguage = $this->languageService->getDefaultLanguage();
        $isDefaultLang = $request->language_id == $defaultLanguage->id;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'language_id' => 'required|exists:languages,id',
            'values' => 'required|array|min:1',
            'values.*.value' => 'required|string',
        ], [
            'values.required' => __('at_least_one_value_required'),
            'values.min' => __('at_least_one_value_required'),
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        if (!$isDefaultLang) {
            return CommonHelper::responseError('default_language_required');
        }

        return DB::transaction(function () use ($request) {
            $slug = $request->input('slug') ?: Str::slug($request->name);
            // ensure uniqueness
            $base = $slug;
            $i = 1;
            // Custom (Lebazone): include deleted attributes - a deleted "Color" still holds the slug "color".
            while (Attribute::withTrashed()->where('slug', $slug)->exists()) {
                $i++;
                $slug = $base . '-' . $i;
            }

            $attribute = Attribute::create([
                'name' => $request->name,
                'slug' => $slug,
                'status' => $request->input('status', 1),
            ]);

            $attribute->saveTranslation($request->language_id, ['name' => $request->name]);

            $this->syncValues($attribute, $request->input('values', []), $request->language_id);

            return CommonHelper::responseWithData([
                'id' => $attribute->id,
                'message' => __('attribute_saved_successfully'),
            ]);
        });
    }

    public function update(Request $request)
    {
        $defaultLanguage = $this->languageService->getDefaultLanguage();
        $isDefaultLang = $request->language_id == $defaultLanguage->id;

        $rules = [
            'id' => 'required|exists:attributes,id',
            'language_id' => 'required|exists:languages,id',
        ];
        if ($isDefaultLang) {
            $rules['name'] = 'required|string';
            $rules['values'] = 'required|array|min:1';
            $rules['values.*.value'] = 'required|string';
        } else {
            $rules['name'] = 'nullable|string';
            $rules['values'] = 'sometimes|array';
        }

        $validator = Validator::make($request->all(), $rules, [
            'values.required' => __('at_least_one_value_required'),
            'values.min' => __('at_least_one_value_required'),
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $attribute = Attribute::find($request->id);
        if (!$attribute) {
            return CommonHelper::responseError('attribute_not_found');
        }

        // a dropped id would otherwise be deleted and stripped from existing variants).
        if ($isDefaultLang) {
            $incomingIds = collect($request->input('values', []))
                ->pluck('id')->filter()->map(fn ($i) => (int) $i)->all();
            $existingIds = AttributeValue::where('attribute_id', $attribute->id)->pluck('id')->all();
            $removedIds = array_values(array_diff($existingIds, $incomingIds));
            if ($removedIds) {
                $usedIds = DB::table('product_variant_attribute_values')
                    ->whereIn('attribute_value_id', $removedIds)
                    ->distinct()->pluck('attribute_value_id')->all();
                if ($usedIds) {
                    // Custom (Lebazone): allowed (the admin confirmed) - take the removed values
                    // off the variants that used them instead of refusing the edit.
                    DB::table('product_variant_attribute_values')->whereIn('attribute_value_id', $usedIds)->delete();
                }
            }
        }

        return DB::transaction(function () use ($request, $attribute, $isDefaultLang) {
            if ($isDefaultLang) {
                $attribute->name = $request->name;
                $attribute->status = $request->status ?? $attribute->status;
                if ($request->filled('slug') && $request->slug !== $attribute->slug) {
                    $newSlug = $request->slug;
                    if (!Attribute::withTrashed()->where('slug', $newSlug)->where('id', '!=', $attribute->id)->exists()) {
                        $attribute->slug = $newSlug;
                    }
                }
                $attribute->save();
            }

            $attribute->saveTranslation($request->language_id, ['name' => $request->name]);

            if ($request->has('values')) {
                $langId = (int) $request->language_id;
                $before = $this->valueTexts($attribute, $langId, $isDefaultLang);
                $this->syncValues($attribute, $request->input('values', []), $request->language_id);
                $after = $this->valueTexts($attribute, $langId, $isDefaultLang);
                // Custom (Lebazone): a renamed value is renamed in the variant names too
                // ("Gold / Large" -> "Golden / Large"), so the shop shows the new word.
                foreach ($before as $valueId => $oldText) {
                    $newText = $after[$valueId] ?? null;
                    if ($newText !== null && $oldText !== '' && $newText !== '' && $newText !== $oldText) {
                        $this->renameInVariantNames((int) $valueId, $langId, $isDefaultLang, $oldText, $newText);
                    }
                }
            }

            return CommonHelper::responseSuccess('attribute_updated_successfully');
        });
    }

    public function delete(Request $request)
    {
        $attribute = Attribute::find($request->id);
        if (!$attribute) {
            return CommonHelper::responseError('attribute_not_found');
        }
        // Block deleting an attribute that's used by any product variant.
        $inUse = DB::table('product_variant_attribute_values')
            ->where('attribute_id', $attribute->id)->exists();
        if ($inUse) {
            return CommonHelper::responseError(__('attribute_in_use_cannot_delete'));
        }
        $attribute->delete();
        return CommonHelper::responseSuccess('attribute_deleted_successfully');
    }

    /** [value_id => text] for one language (default language: the value column). */
    protected function valueTexts(Attribute $attribute, int $langId, bool $isDefaultLang): array
    {
        $ids = AttributeValue::where('attribute_id', $attribute->id)->pluck('value', 'id')->all();
        if ($isDefaultLang) return array_map(fn ($v) => trim((string) $v), $ids);
        $tr = DB::table('attribute_value_translations')->whereIn('attribute_value_id', array_keys($ids))
            ->where('language_id', $langId)->pluck('value', 'attribute_value_id')->all();
        return array_map(fn ($v) => trim((string) $v), $tr);
    }

    /** Replace the "old" segment of "A / B" variant names with the new text, for variants using this value. */
    protected function renameInVariantNames(int $valueId, int $langId, bool $isDefaultLang, string $old, string $new): void
    {
        $variantIds = DB::table('product_variant_attribute_values')->where('attribute_value_id', $valueId)
            ->pluck('product_variant_id')->unique()->all();
        if (!$variantIds) return;
        $swap = function ($name) use ($old, $new) {
            $parts = array_map('trim', explode(' / ', (string) $name));
            $changed = false;
            foreach ($parts as $i => $p) {
                if (mb_strtolower($p) === mb_strtolower($old)) { $parts[$i] = $new; $changed = true; }
            }
            return $changed ? implode(' / ', $parts) : null;
        };
        foreach (DB::table('product_variant_translations')->whereIn('product_variant_id', $variantIds)
                     ->where('language_id', $langId)->get(['id', 'name']) as $row) {
            if (($n = $swap($row->name)) !== null) {
                DB::table('product_variant_translations')->where('id', $row->id)->update(['name' => $n]);
            }
        }
        if ($isDefaultLang && \Illuminate\Support\Facades\Schema::hasColumn('product_variants', 'name')) {
            foreach (DB::table('product_variants')->whereIn('id', $variantIds)->get(['id', 'name']) as $row) {
                if (($n = $swap($row->name)) !== null) {
                    DB::table('product_variants')->where('id', $row->id)->update(['name' => $n]);
                }
            }
        }
    }

    /**
     * Sync child values: incoming list = full set for default language.
     * Items with `id` are updated; items without are created; missing ids deleted.
     * For non-default language, only updates translation rows.
     */
    protected function syncValues(Attribute $attribute, array $values, int $languageId): void
    {
        $defaultLanguage = $this->languageService->getDefaultLanguage();
        $isDefaultLang = $languageId == $defaultLanguage->id;

        $incomingIds = [];

        foreach ($values as $row) {
            $id = $row['id'] ?? null;
            $value = $row['value'] ?? '';

            if ($id) {
                $av = AttributeValue::where('attribute_id', $attribute->id)->find($id);
                if (!$av) {
                    continue;
                }
                if ($isDefaultLang) {
                    $av->value = $value;
                    $av->save();
                }

                $av->saveTranslation($languageId, ['value' => $value]);
                $incomingIds[] = $av->id;
            } else if ($isDefaultLang) {
                $av = AttributeValue::create([
                    'attribute_id' => $attribute->id,
                    'value' => $value,
                ]);
                $av->saveTranslation($languageId, ['value' => $value]);
                $incomingIds[] = $av->id;
            }
        }

        // Only delete missing on default language sync
        if ($isDefaultLang && !empty($incomingIds)) {
            AttributeValue::where('attribute_id', $attribute->id)
                ->whereNotIn('id', $incomingIds)
                ->delete();
        } elseif ($isDefaultLang && empty($values)) {
            AttributeValue::where('attribute_id', $attribute->id)->delete();
        }
    }
}
