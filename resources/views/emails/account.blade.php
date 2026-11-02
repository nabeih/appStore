<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $heading }}</title></head>
<body style="font-family:Tahoma,Arial,sans-serif;direction:rtl;background:#f1f5f9;padding:24px;color:#1e293b">
<div style="max-width:560px;margin:auto;background:white;padding:28px;border-radius:16px">
<h1>{{ $heading }}</h1><p>{{ $messageText }}</p>
<p><a href="{{ $actionUrl }}" style="display:inline-block;background:#4f46e5;color:white;padding:14px 24px;border-radius:8px;text-decoration:none">{{ $actionLabel }}</a></p>
<p>تنتهي صلاحية الرابط خلال {{ $minutes }} دقيقة. إذا لم تطلب هذه الرسالة، تجاهلها ولا تشارك الرابط مع أحد.</p>
<p>لا نطلب منك إرسال كلمة المرور أو الرد بها على البريد.</p>
</div>
</body></html>
