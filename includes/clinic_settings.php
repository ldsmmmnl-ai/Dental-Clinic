<?php
// clinic_settings.php — Reads clinic branding from the `settings` table once per request.
// Usage: $cs = clinic_settings($conn);
//   $cs['name']     — e.g. "Honeytooth Dental Clinic"
//   $cs['subtitle'] — e.g. "Your Trusted Dental Care Partner"
//   $cs['address']  — e.g. "123 Main St, Iloilo City"
//   $cs['phone']    — e.g. "+63 912 345 6789"
//   $cs['landline'] — e.g. "(033) 509 68 46" (optional, printed on prescriptions)
//   $cs['email']    — e.g. "info@honeytooth.ph"
//   $cs['logo_url'] — absolute URL to logo image, or '' if none uploaded
//   $cs['logo_html']— ready-to-embed <img> tag or the 🦷 emoji fallback
//   $cs['logo_text']— plain emoji + name for text-only spots (e.g. footer)

function clinic_settings(PDO $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // Pull all rows at once — single query, no N+1
    $rows = $conn->query(
        "SELECT setting_key, setting_value FROM settings WHERE setting_key IN (
            'clinic_name','clinic_subtitle','clinic_address',
            'clinic_phone','clinic_landline','clinic_email','clinic_logo'
        )"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $name     = trim($rows['clinic_name']     ?? '') ?: 'DentalCare Clinic';
    $subtitle = trim($rows['clinic_subtitle'] ?? '') ?: 'Dental Clinic Management System';
    $address  = trim($rows['clinic_address']  ?? '');
    $phone    = trim($rows['clinic_phone']    ?? '');
    $landline = trim($rows['clinic_landline'] ?? '');
    $email    = trim($rows['clinic_email']    ?? '');
    $logo_val = trim($rows['clinic_logo']     ?? '');

    // If the saved logo file is no longer on disk (e.g. the server was redeployed), behave as
    // "no logo" so every page falls back to the default icon instead of showing a broken image.
    if ($logo_val !== '' && !is_file(dirname(__DIR__) . '/' . ltrim($logo_val, '/'))) {
        $logo_val = '';
    }

    // logo_val is stored as a relative path like "assets/images/logos/my_logo.png"
    $logo_url = '';
    if ($logo_val !== '') {
        // Build absolute URL using BASE_URL (defined in config.php before this is called)
        $logo_url = rtrim(BASE_URL, '/') . '/' . ltrim($logo_val, '/');
    }

    // Ready-to-use HTML logo block — fully self-contained (inline styles only), so it
    // looks the same wherever it's dropped in: a small white rounded card with a soft
    // shadow behind the image, sized consistently and legible on both light headers
    // and colored/dark headers. No dependency on any page's own .clinic-logo CSS.
    if ($logo_url !== '') {
        $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safe_url  = htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8');
        $logo_html = '<span style="display:inline-flex;align-items:center;justify-content:center;'
                   . 'background:#fff;border-radius:10px;padding:4px;box-shadow:0 1px 3px rgba(15,23,42,0.15);'
                   . 'flex-shrink:0;line-height:0;">'
                   . "<img src=\"{$safe_url}\" alt=\"{$safe_name} logo\" "
                   . 'style="height:44px;width:auto;max-width:140px;object-fit:contain;display:block;border-radius:6px;">'
                   . '</span>';
    } else {
        $logo_html = '<span style="display:inline-flex;align-items:center;justify-content:center;'
                   . 'width:48px;height:48px;background:#2563eb;border-radius:12px;'
                   . 'font-size:24px;flex-shrink:0;">🦷</span>';
    }

    $cache = [
        'name'      => $name,
        'subtitle'  => $subtitle,
        'address'   => $address,
        'phone'     => $phone,
        'landline'  => $landline,
        'email'     => $email,
        'logo_url'  => $logo_url,
        'logo_html' => $logo_html,
        'logo_text' => '🦷 ' . $name,
    ];
    return $cache;
}