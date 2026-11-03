<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Custom (Lebazone): AI helpers for Purchasing → store listing.
 *
 *  - photo(): makes ONE extra product photo from the item's real picture + name, in a given style
 *    (studio / angle / lifestyle / detail / packaging). Providers: Google Gemini, OpenAI.
 *  - description(): writes the product description. Providers: Gemini, OpenAI, or Claude (Anthropic API).
 *
 * The provider + API key live in pur_settings (Purchasing → Settings → AI). The calls run in the
 * background queue (see PurchasingApiController::aiStart) so the site never waits on them.
 */
class PurchasingAi
{
    public const STYLES = [
        'studio'    => 'Professional e-commerce studio photo of this exact product on a pure white background, soft even lighting, centred, sharp focus, no text.',
        'angle'     => 'The same exact product photographed from a different angle (three-quarter view), pure white background, studio lighting, no text.',
        'lifestyle' => 'Lifestyle photo of this exact product being used in a real, tasteful home setting that fits what it is, natural light, product clearly visible and in focus, no text.',
        'detail'    => 'Close-up detail shot of this exact product showing its material, texture and finish, shallow depth of field, clean background, no text.',
        'packaging' => 'This exact product shown together with its retail packaging or gift box on a clean light background, studio lighting, no text.',
    ];

    private const KEEP = ' Keep the product IDENTICAL to the reference photo: same shape, proportions, colours, materials, logos, number of parts. Do not add or remove features. Square 1:1 image.';

    public static function setting(string $k, $def = null)
    {
        $v = DB::table('pur_settings')->where('key', $k)->value('value');
        return $v === null || $v === '' ? $def : $v;
    }

    public static function config(): array
    {
        $img = (string) self::setting('ai_provider', '');
        $txt = (string) self::setting('ai_text_provider', '') ?: $img;
        return [
            'image_provider' => $img,                                  // '' | gemini | openai
            'image_key'      => (string) self::setting('ai_key', ''),
            'text_provider'  => $txt,                                  // '' | gemini | openai | claude
            'text_key'       => $txt === 'claude' ? (string) self::setting('claude_key', '') : (string) self::setting('ai_key', ''),
            'gemini_image_model' => (string) self::setting('gemini_image_model', 'gemini-2.5-flash-image'),
            'gemini_text_model'  => (string) self::setting('gemini_text_model', 'gemini-2.5-flash'),
            'openai_image_model' => (string) self::setting('openai_image_model', 'gpt-image-1'),
            'openai_text_model'  => (string) self::setting('openai_text_model', 'gpt-4o-mini'),
            'claude_model'       => (string) self::setting('claude_model', 'claude-haiku-5-5'),
        ];
    }

    public static function canPhotos(): bool { $c = self::config(); return in_array($c['image_provider'], ['gemini', 'openai'], true) && $c['image_key'] !== ''; }
    public static function canText(): bool { $c = self::config(); return in_array($c['text_provider'], ['gemini', 'openai', 'claude'], true) && $c['text_key'] !== ''; }

    private static function readPicture(?string $path): array
    {
        if (!$path || !Storage::disk('public')->exists($path)) throw new \RuntimeException('The item has no picture to start from');
        $bytes = Storage::disk('public')->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg';
        return [$bytes, $mime];
    }

    private static function savePng(string $b64): string
    {
        $bin = base64_decode($b64);
        if (!$bin) throw new \RuntimeException('The AI did not return a picture');
        Storage::disk('public')->makeDirectory('purchasing/ai');
        $path = 'purchasing/ai/' . date('Ymd') . '-' . Str::random(12);
        // Store as JPG (smaller) when GD is available.
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($bin))) {
            $w = imagesx($img); $h = imagesy($img);
            $out = imagecreatetruecolor($w, $h);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
            imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
            imagejpeg($out, Storage::disk('public')->path($path . '.jpg'), 88);
            imagedestroy($img); imagedestroy($out);
            return $path . '.jpg';
        }
        Storage::disk('public')->put($path . '.png', $bin);
        return $path . '.png';
    }

    private static function fail($res, string $who): \RuntimeException
    {
        $j = $res->json();
        $msg = $j['error']['message'] ?? $j['error']['type'] ?? $j['message'] ?? substr((string) $res->body(), 0, 300);
        return new \RuntimeException("$who: " . $msg);
    }

    /** One extra photo. Returns the storage path. */
    public static function photo(object $item, string $style): string
    {
        $c = self::config();
        if (!self::canPhotos()) throw new \RuntimeException('Set up AI photos first: Purchasing → Settings → AI');
        [$bytes, $mime] = self::readPicture($item->picture);
        $prompt = (self::STYLES[$style] ?? self::STYLES['studio']) . ' The product is: ' . $item->name
            . ($item->variants ? ' (options: ' . $item->variants . ')' : '') . '.' . self::KEEP;

        if ($c['image_provider'] === 'gemini') {
            $res = Http::timeout(180)->withHeaders(['x-goog-api-key' => $c['image_key']])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/' . $c['gemini_image_model'] . ':generateContent', [
                    'contents' => [['parts' => [
                        ['text' => $prompt],
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                    ]]],
                    'generationConfig' => ['responseModalities' => ['IMAGE']],
                ]);
            if (!$res->successful()) throw self::fail($res, 'Gemini');
            foreach ($res->json('candidates.0.content.parts', []) as $p) {
                $d = $p['inlineData']['data'] ?? $p['inline_data']['data'] ?? null;
                if ($d) return self::savePng($d);
            }
            throw new \RuntimeException('Gemini did not return a picture (it may have refused this product)');
        }

        // OpenAI image edit with the real photo as reference
        $res = Http::timeout(180)->withToken($c['image_key'])
            ->attach('image', $bytes, 'product.' . (str_contains($mime, 'png') ? 'png' : 'jpg'), ['Content-Type' => $mime])
            ->post('https://api.openai.com/v1/images/edits', [
                'model' => $c['openai_image_model'], 'prompt' => $prompt, 'size' => '1024x1024', 'n' => 1,
            ]);
        if (!$res->successful()) throw self::fail($res, 'OpenAI');
        $b64 = $res->json('data.0.b64_json');
        if (!$b64) throw new \RuntimeException('OpenAI did not return a picture');
        return self::savePng($b64);
    }

    /** Product description (simple HTML: a short paragraph + bullet list). */
    public static function description(object $item): string
    {
        $c = self::config();
        if (!self::canText()) throw new \RuntimeException('Set up AI descriptions first: Purchasing → Settings → AI');
        $pic = null;
        try { $pic = self::readPicture($item->picture); } catch (\Throwable $e) { /* text only */ }
        $size = collect([$item->item_l, $item->item_w, $item->item_h])->filter(fn ($v) => $v !== null && $v !== '')->implode(' × ');
        $prompt = "Write an online-store product description for the Lebazone shop (Lebanon).\n"
            . "Product: {$item->name}\n"
            . ($item->variants ? "Options / colours: {$item->variants}\n" : '')
            . ($size ? "Size of one piece incl. packaging (cm): $size\n" : '')
            . ($item->notes ? "Notes from the buyer: {$item->notes}\n" : '')
            . "Rules: English. Friendly and clear. 1 short paragraph (2-3 sentences) then 3-5 bullet points with practical features. "
            . "Only describe what you can see in the photo or what is given above — never invent materials, brands, certifications, battery life or sizes. "
            . "Return ONLY simple HTML using <p>, <ul>, <li>, <strong>. No title, no price, no markdown.";

        if ($c['text_provider'] === 'claude') {
            $content = [];
            if ($pic) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $pic[1], 'data' => base64_encode($pic[0])]];
            $content[] = ['type' => 'text', 'text' => $prompt];
            $res = Http::timeout(90)->withHeaders(['x-api-key' => $c['text_key'], 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', ['model' => $c['claude_model'], 'max_tokens' => 700, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (!$res->successful()) throw self::fail($res, 'Claude');
            $out = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
        } elseif ($c['text_provider'] === 'gemini') {
            $parts = [['text' => $prompt]];
            if ($pic) $parts[] = ['inline_data' => ['mime_type' => $pic[1], 'data' => base64_encode($pic[0])]];
            $res = Http::timeout(90)->withHeaders(['x-goog-api-key' => $c['text_key']])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/' . $c['gemini_text_model'] . ':generateContent', ['contents' => [['parts' => $parts]]]);
            if (!$res->successful()) throw self::fail($res, 'Gemini');
            $out = collect($res->json('candidates.0.content.parts', []))->pluck('text')->filter()->implode("\n");
        } else {
            $content = [['type' => 'text', 'text' => $prompt]];
            if ($pic) $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $pic[1] . ';base64,' . base64_encode($pic[0])]];
            $res = Http::timeout(90)->withToken($c['text_key'])
                ->post('https://api.openai.com/v1/chat/completions', ['model' => $c['openai_text_model'], 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (!$res->successful()) throw self::fail($res, 'OpenAI');
            $out = (string) $res->json('choices.0.message.content', '');
        }
        $out = trim(preg_replace('/^```(?:html)?\s*|\s*```$/i', '', trim($out)));
        if ($out === '') throw new \RuntimeException('The AI returned an empty description');
        return strip_tags($out, '<p><ul><ol><li><strong><b><em><br>');
    }

    /** Run one queued job row (called from the queue worker). */
    public static function runJob(int $jobId): void
    {
        $job = DB::table('pur_ai_jobs')->find($jobId);
        if (!$job || $job->status !== 'queued') return;
        DB::table('pur_ai_jobs')->where('id', $jobId)->update(['status' => 'running', 'updated_at' => now()]);
        try {
            $item = DB::table('pur_items')->find($job->item_id);
            if (!$item) throw new \RuntimeException('Item not found');
            $result = $job->kind === 'description' ? self::description($item) : self::photo($item, (string) $job->style);
            DB::table('pur_ai_jobs')->where('id', $jobId)->update(['status' => 'done', 'result' => $result, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('pur_ai_jobs')->where('id', $jobId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
        }
    }
}
