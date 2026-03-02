<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8" />
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; }
        .header { text-align: center; margin-bottom: 24px; }
        .header h1 { color: #333; font-size: 22px; }
        .info-box { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px; padding: 16px; margin: 20px 0; }
        .info-box p { margin: 8px 0; color: #555; font-size: 15px; }
        .info-box strong { color: #222; }
        .footer { text-align: center; margin-top: 32px; color: #aaa; font-size: 12px; }
        .btn { display: inline-block; margin-top: 20px; padding: 12px 28px; background: #4F46E5; color: #fff;
               text-decoration: none; border-radius: 6px; font-size: 15px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Welcome to the Platform 👋</h1>
    </div>

    <p>Dear <strong>{{ $fullName }}</strong>,</p>
    <p>Your account has been successfully created for the store <strong>{{ $storeName }}</strong>. Below are your login details:</p>

    <div class="info-box">
        <p>📧 <strong>Email:</strong> {{ $email }}</p>
        <p>🔑 <strong>Password:</strong> {{ $plainPassword }}</p>
    </div>

    <p>For security reasons, we recommend changing your password after your first login.</p>

    <div style="text-align:center">
        <a href="{{ config('app.url') }}/login" class="btn">Login Now</a>
    </div>

    <div class="footer">
        <p>If you did not request this account, please ignore this email.</p>
    </div>
</div>
</body>
</html>