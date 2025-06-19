<?php
/**
 * File: views/emails/tracking_notification.php
 * Location: views/emails/tracking_notification.php
 *
 * WinABN Tracking Notification Email Template
 *
 * Email template sent to winners when their prize has been shipped with tracking information.
 * Variables available: first_name, last_name, prize_name, tracking_number, tracking_url,
 * shipping_provider, estimated_delivery, shipping_address, game_name
 *
 * @package WinABN\Views\Emails
 * @author WinABN Development Team
 * @version 1.0
 */

// This file is included by EmailSender and parsed for @subject, @html, and @text sections
?>

@subject: 📦 Your {{prize_name}} is on its way! Track your delivery

@html:
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prize Shipped - Tracking Information</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .header .package {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
        }
        .content {
            padding: 40px 30px;
        }
        .shipped-banner {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 30px;
        }
        .shipped-banner h2 {
            margin: 0 0 10px 0;
            font-size: 22px;
            font-weight: 600;
        }
        .shipped-banner p {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
        }
        .tracking-card {
            background: linear-gradient(135deg, #007cba 0%, #0056b3 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            margin: 25px 0;
            position: relative;
            overflow: hidden;
        }
        .tracking-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shine 3s infinite;
        }
        @keyframes shine {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        .tracking-number {
            font-size: 24px;
            font-weight: 700;
            margin: 15px 0;
            letter-spacing: 2px;
            background-color: rgba(255,255,255,0.2);
            padding: 15px;
            border-radius: 8px;
            word-break: break-all;
        }
        .track-button {
            display: inline-block;
            background: #ff6b35;
            color: white;
            text-decoration: none;
            padding: 18px 40px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            margin: 20px 0;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .track-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }
        .shipping-details {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e9ecef;
        }
        .detail-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }
        .detail-label {
            color: #6c757d;
            font-weight: 500;
        }
        .detail-value {
            font-weight: 600;
            text-align: right;
            max-width: 60%;
            word-break: break-word;
        }
        .delivery-timeline {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 1px solid #90caf9;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
        }
        .delivery-timeline h3 {
            margin: 0 0 20px 0;
            color: #1565c0;
            font-size: 20px;
            text-align: center;
        }
        .timeline-step {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            position: relative;
        }
        .timeline-step:last-child {
            margin-bottom: 0;
        }
        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .step-completed {
            background: #28a745;
            color: white;
        }
        .step-current {
            background: #007cba;
            color: white;
            animation: pulse 2s infinite;
        }
        .step-pending {
            background: #e9ecef;
            color: #6c757d;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(0, 124, 186, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(0, 124, 186, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 124, 186, 0); }
        }
        .step-text {
            flex: 1;
        }
        .step-title {
            font-weight: 600;
            margin-bottom: 2px;
        }
        .step-desc {
            font-size: 14px;
            color: #6c757d;
        }
        .delivery-estimate {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 25px 0;
        }
        .delivery-estimate h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        .estimate-date {
            font-size: 24px;
            font-weight: 700;
            margin-top: 10px;
        }
        .tips-section {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }
        .tips-section h3 {
            margin: 0 0 15px 0;
            color: #856404;
            font-size: 18px;
        }
        .tips-section ul {
            margin: 0;
            padding-left: 20px;
        }
        .tips-section li {
            margin-bottom: 8px;
            color: #856404;
        }
        .contact-section {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }
        .contact-section h3 {
            margin: 0 0 15px 0;
            color: #0056b3;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 0;
            color: #6c757d;
            font-size: 14px;
        }
        .prize-highlight {
            background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%);
            color: #856404;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 25px 0;
            border: 2px solid #ffc107;
        }
        .prize-highlight h3 {
            margin: 0 0 10px 0;
            font-size: 20px;
        }
        @media (max-width: 600px) {
            .container {
                margin: 0;
                border-radius: 0;
            }
            .header, .content, .footer {
                padding: 25px 20px;
            }
            .header h1 {
                font-size: 24px;
            }
            .header .package {
                font-size: 48px;
            }
            .track-button {
                display: block;
                margin: 20px 0;
            }
            .detail-row {
                flex-direction: column;
                gap: 5px;
            }
            .detail-value {
                text-align: left;
                max-width: 100%;
            }
            .tracking-number {
                font-size: 18px;
                letter-spacing: 1px;
            }
            .timeline-step {
                flex-direction: column;
                text-align: center;
            }
            .step-icon {
                margin-right: 0;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <span class="package">📦</span>
            <h1>Prize Shipped!</h1>
        </div>

        <div class="content">
            <div class="shipped-banner">
                <h2>Great news {{first_name}}! Your prize is on its way!</h2>
                <p>We've carefully packaged and shipped your prize. Track its journey below.</p>
            </div>

            <div class="prize-highlight">
                <h3>🏆 Your {{game_name}} Prize</h3>
                <div style="font-size: 18px; font-weight: 600;">{{prize_name}}</div>
            </div>

            <div class="tracking-card">
                <h3 style="margin: 0 0 15px 0;">📍 Tracking Information</h3>
                <p style="margin: 0; opacity: 0.9;">Your tracking number:</p>
                <div class="tracking-number">{{tracking_number}}</div>
                <a href="{{tracking_url}}" class="track-button">
                    🔍 Track Your Package
                </a>
            </div>

            <div class="shipping-details">
                <h3 style="margin: 0 0 20px 0; color: #007cba;">🚚 Shipping Details</h3>
                <div class="detail-row">
                    <span class="detail-label">Carrier:</span>
                    <span class="detail-value">{{shipping_provider}}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Tracking Number:</span>
                    <span class="detail-value">{{tracking_number}}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Shipped Date:</span>
                    <span class="detail-value">{{current_date}}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Delivery Address:</span>
                    <span class="detail-value">{{shipping_address}}</span>
                </div>
                {{#if estimated_delivery}}
                <div class="detail-row">
                    <span class="detail-label">Estimated Delivery:</span>
                    <span class="detail-value">{{estimated_delivery}}</span>
                </div>
                {{/if}}
            </div>

            {{#if estimated_delivery}}
            <div class="delivery-estimate">
                <h3>📅 Estimated Delivery</h3>
                <p style="margin: 0; opacity: 0.9;">Your prize should arrive by:</p>
                <div class="estimate-date">{{estimated_delivery}}</div>
            </div>
            {{/if}}

            <div class="delivery-timeline">
                <h3>🛤️ Delivery Journey</h3>
                <div class="timeline-step">
                    <div class="step-icon step-completed">✓</div>
                    <div class="step-text">
                        <div class="step-title">Order Processed</div>
                        <div class="step-desc">Your prize has been prepared for shipping</div>
                    </div>
                </div>
                <div class="timeline-step">
                    <div class="step-icon step-current">📦</div>
                    <div class="step-text">
                        <div class="step-title">In Transit</div>
                        <div class="step-desc">Your package is on its way to you</div>
                    </div>
                </div>
                <div class="timeline-step">
                    <div class="step-icon step-pending">🚚</div>
                    <div class="step-text">
                        <div class="step-title">Out for Delivery</div>
                        <div class="step-desc">Package is with your local delivery team</div>
                    </div>
                </div>
                <div class="timeline-step">
                    <div class="step-icon step-pending">🏠</div>
                    <div class="step-text">
                        <div class="step-title">Delivered</div>
                        <div class="step-desc">Package delivered to your address</div>
                    </div>
                </div>
            </div>

            <div class="tips-section">
                <h3>💡 Delivery Tips</h3>
                <ul>
                    <li><strong>Track regularly:</strong> Check the tracking link for real-time updates</li>
                    <li><strong>Be available:</strong> Someone should be present to receive the package</li>
                    <li><strong>Check safe places:</strong> Couriers may leave packages in designated safe locations</li>
                    <li><strong>Contact courier:</strong> If you miss delivery, contact the carrier directly</li>
                    <li><strong>Inspect package:</strong> Check for any damage upon delivery</li>
                </ul>
            </div>

            <div class="contact-section">
                <h3>📞 Need Help?</h3>
                <p>
                    If you have any questions about your delivery or need to update your address,
                    contact our support team immediately.
                </p>
                <p style="margin-top: 15px;">
                    <strong>Email:</strong> <a href="mailto:{{support_email}}" style="color: #007cba;">{{support_email}}</a>
                </p>
            </div>

            <p style="margin-top: 30px; text-align: center; font-size: 18px; color: #28a745; font-weight: 600;">
                🎉 Congratulations again on winning {{game_name}}! 🎉<br>
                We hope you enjoy your amazing prize!
            </p>
        </div>

        <div class="footer">
            <p><strong>{{app_name}}</strong></p>
            <p>The UK's most exciting competition platform</p>

            <p style="margin-top: 20px; font-size: 12px; color: #adb5bd;">
                This tracking notification was sent to {{first_name}} {{last_name}} because your prize has been shipped.<br>
                Tracking: {{tracking_number}} via {{shipping_provider}}<br>
                Questions? Contact us at {{support_email}}<br>
                © {{current_year}} {{app_name}}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>

@text:
📦 PRIZE SHIPPED!

Great news {{first_name}}! Your prize is on its way!

🏆 YOUR {{game_name}} PRIZE:
{{prize_name}}

📍 TRACKING INFORMATION:
Tracking Number: {{tracking_number}}
Carrier: {{shipping_provider}}
Shipped Date: {{current_date}}
{{#if estimated_delivery}}Estimated Delivery: {{estimated_delivery}}{{/if}}

TRACK YOUR PACKAGE:
{{tracking_url}}

DELIVERY ADDRESS:
{{shipping_address}}

🛤️ DELIVERY JOURNEY:
✓ Order Processed - Your prize has been prepared for shipping
📦 In Transit - Your package is on its way to you
🚚 Out for Delivery - Package is with your local delivery team
🏠 Delivered - Package delivered to your address

💡 DELIVERY TIPS:
• Track regularly: Check the tracking link for real-time updates
• Be available: Someone should be present to receive the package
• Check safe places: Couriers may leave packages in designated safe locations
• Contact courier: If you miss delivery, contact the carrier directly
• Inspect package: Check for any damage upon delivery

NEED HELP?
If you have any questions about your delivery or need to update your address, contact our support team immediately.

Email: {{support_email}}

🎉 Congratulations again on winning {{game_name}}! 🎉
We hope you enjoy your amazing prize!

{{app_name}} - The UK's most exciting competition platform
{{app_url}}

Tracking: {{tracking_number}} via {{shipping_provider}}
Questions? Contact us at {{support_email}}
© {{current_year}} {{app_name}}. All rights reserved.
