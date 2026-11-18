<?php

namespace App\Support\Guidance;

use App\Models\User;

class AssistantService
{
    public function __construct(private GuidanceKnowledgeBase $knowledge, private GuidanceResolver $resolver, private AssistantContextBuilder $context) {}

    public function answer(User $actor, array $input): array
    {
        $question = $this->normalize($input['question'] ?? '');
        $definitions = $this->knowledge->definitions();
        $scores = [];
        foreach ($definitions as $key => $definition) {
            $scores[$key] = 0;
            foreach ($definition['keywords'] as $keyword) {
                if ($question !== '' && str_contains($question, $this->normalize($keyword))) {
                    $scores[$key] += mb_strlen($keyword);
                }
            }
        }
        arsort($scores);
        $topic = reset($scores) > 0 ? array_key_first($scores) : ($input['page'] ?? null);
        $allowed = $this->knowledge->allowed($actor);
        $known = $topic !== null && isset($allowed[$topic]);
        $context = $this->context->build($actor, $input, $known ? $topic : null);
        $branchId = $context['branch']['id'] ?? null;
        $guidance = ! empty($input['code']) ? $this->resolver->resolve($input['code'], $actor, $branchId) : null;
        $answer = $known ? $allowed[$topic]['description'] : 'لا أستطيع تحديد إجراء مؤكد ومسموح من السؤال الحالي. اختر موضوعًا من المساعدة أو اذكر اسم الشاشة وما تريد فعله، دون كلمات مرور أو بيانات زبائن.';
        $steps = $known ? $allowed[$topic]['steps'] : [];
        if ($guidance) {
            $answer = 'شرح الرسالة: '.$guidance['title'].' — '.$guidance['message'];
            $steps = [$guidance['next_step']];
        }
        if (isset($context['readiness'])) {
            $ready = $context['readiness'];
            $answer .= ' نتيجة الفحص الحالي: '.$ready['ready_count'].' صنفًا متاحًا وجاهزًا، و'.$ready['not_ready_count'].' يحتاج مراجعة.';
            $steps = array_merge($ready['blockers'], $steps);
        }
        if (isset($context['document'])) {
            $status = match ($context['document']['status']) {
                'draft' => 'مسودة', 'approved' => 'معتمد', 'cancelled' => 'ملغى', 'reversed' => 'معكوس', default => 'تحتاج مراجعة الصفحة',
            };
            $answer .= ' حالة المستند الحالية: '.$status.'.';
        }
        $link = $known ? $this->knowledge->link($topic, $actor, $branchId) : null;

        return ['answer' => $answer, 'steps' => $steps, 'links' => $link ? [$link] : [], 'guidance' => $guidance,
            'context' => $context, 'provider' => 'local_rules', 'external_ai' => false, 'uncertain' => ! $known && ! $guidance];
    }

    private function normalize(string $text): string
    {
        return strtr(mb_strtolower(preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text)), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }
}
