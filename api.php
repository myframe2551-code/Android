<?php
require_once __DIR__.'/config.php';

function random_otp() {
    return str_pad((string)random_int(0,999999), 6, '0', STR_PAD_LEFT);
}

function valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function order_no() {
    return 'BT'.date('ymdHis').str_pad((string)random_int(0,999),3,'0',STR_PAD_LEFT);
}

function send_otp($email, $purpose) {
    $pdo = db();
    $code = random_otp();
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + OTP_EXPIRE_MINUTES * 60);

    $stmt = $pdo->prepare('DELETE FROM otp_codes WHERE email=? AND purpose=?');
    $stmt->execute(array($email,$purpose));

    $stmt = $pdo->prepare('INSERT INTO otp_codes(email,code_hash,purpose,expires_at) VALUES(?,?,?,?)');
    $stmt->execute(array($email,$hash,$purpose,$expires));

    $subject = 'รหัส OTP สำหรับ APP BETAGEN';
    $body = "รหัส OTP ของคุณคือ: ".$code."\n\nรหัสนี้หมดอายุภายใน ".OTP_EXPIRE_MINUTES." นาที\n\nAPP BETAGEN";

    if (!send_mail_text($email,$subject,$body)) {
        return false;
    }
    return true;
}

function verify_otp($email,$code,$purpose) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM otp_codes WHERE email=? AND purpose=? ORDER BY id DESC LIMIT 1');
    $stmt->execute(array($email,$purpose));
    $row = $stmt->fetch();

    if (!$row) return false;
    if (strtotime($row['expires_at']) < time()) return false;
    if ((int)$row['attempts'] >= 5) return false;

    $pdo->prepare('UPDATE otp_codes SET attempts=attempts+1 WHERE id=?')->execute(array($row['id']));

    if (!password_verify($code,$row['code_hash'])) return false;

    $pdo->prepare('DELETE FROM otp_codes WHERE id=?')->execute(array($row['id']));
    return true;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'register_request') {
    $data = input_json();
    $email = strtolower(clean(isset($data['email'])?$data['email']:''));
    $password = (string)(isset($data['password'])?$data['password']:'');

    if (!valid_email($email)) json_response(array('ok'=>false,'message'=>'อีเมล์ไม่ถูกต้อง'),422);
    if (strlen($password) < 6) json_response(array('ok'=>false,'message'=>'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร'),422);

    $stmt = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $stmt->execute(array($email));
    if ($stmt->fetch()) json_response(array('ok'=>false,'message'=>'อีเมล์นี้มีบัญชีแล้ว'),409);

    $_SESSION['pending_register'] = array(
        'email'=>$email,
        'password_hash'=>password_hash($password,PASSWORD_DEFAULT)
    );

    if (!send_otp($email,'register')) {
        json_response(array('ok'=>false,'message'=>'ไม่สามารถส่ง OTP ได้ กรุณาตรวจสอบระบบอีเมล์'),500);
    }

    json_response(array('ok'=>true,'message'=>'ส่ง OTP ไปที่อีเมล์แล้ว'));
}

if ($action === 'register_verify') {
    $data = input_json();
    $code = clean(isset($data['otp'])?$data['otp']:'');
    $pending = isset($_SESSION['pending_register']) ? $_SESSION['pending_register'] : null;

    if (!$pending) json_response(array('ok'=>false,'message'=>'ไม่มีรายการสมัครที่รอยืนยัน'),400);
    if (!preg_match('/^[0-9]{6}$/',$code)) json_response(array('ok'=>false,'message'=>'OTP ไม่ถูกต้อง'),422);

    if (!verify_otp($pending['email'],$code,'register')) {
        json_response(array('ok'=>false,'message'=>'OTP ไม่ถูกต้องหรือหมดอายุ'),422);
    }

    $stmt = db()->prepare('INSERT INTO users(email,password_hash,email_verified_at) VALUES(?,?,NOW())');
    $stmt->execute(array($pending['email'],$pending['password_hash']));
    $_SESSION['user_id'] = db()->lastInsertId();
    unset($_SESSION['pending_register']);

    json_response(array('ok'=>true,'message'=>'สมัครสมาชิกสำเร็จ'));
}

if ($action === 'login') {
    $data = input_json();
    $email = strtolower(clean(isset($data['email'])?$data['email']:''));
    $password = (string)(isset($data['password'])?$data['password']:'');

    $stmt = db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
    $stmt->execute(array($email));
    $user = $stmt->fetch();

    if (!$user || !password_verify($password,$user['password_hash'])) {
        json_response(array('ok'=>false,'message'=>'อีเมล์หรือรหัสผ่านไม่ถูกต้อง'),401);
    }

    $_SESSION['user_id'] = $user['id'];
    json_response(array('ok'=>true,'message'=>'เข้าสู่ระบบสำเร็จ'));
}

if ($action === 'logout') {
    $_SESSION = array();
    session_destroy();
    json_response(array('ok'=>true));
}

if ($action === 'me') {
    $user = current_user();
    json_response(array('ok'=>true,'user'=>$user));
}

if ($action === 'enable_seller') {
    $user = require_user();
    db()->prepare('UPDATE users SET is_seller=1 WHERE id=?')->execute(array($user['id']));
    json_response(array('ok'=>true,'message'=>'เปิดใช้งานโหมดเซลล์แล้ว'));
}

if ($action === 'seller_areas') {
    $user = require_seller();
    $stmt = db()->prepare('SELECT * FROM seller_areas WHERE user_id=? ORDER BY province,district,subdistrict');
    $stmt->execute(array($user['id']));
    $areas = $stmt->fetchAll();

    foreach ($areas as &$area) {
        $s = db()->prepare('SELECT id,day_of_week,round_no FROM seller_routes WHERE seller_area_id=? ORDER BY day_of_week,round_no');
        $s->execute(array($area['id']));
        $area['routes'] = $s->fetchAll();
    }

    json_response(array('ok'=>true,'areas'=>$areas));
}

if ($action === 'seller_area_save') {
    $user = require_seller();
    $data = input_json();

    $province = clean(isset($data['province'])?$data['province']:'');
    $district = clean(isset($data['district'])?$data['district']:'');
    $subdistrict = clean(isset($data['subdistrict'])?$data['subdistrict']:'');

    if ($province==='' || $district==='' || $subdistrict==='') {
        json_response(array('ok'=>false,'message'=>'กรุณาเลือกพื้นที่ให้ครบ'),422);
    }

    $stmt = db()->prepare('INSERT INTO seller_areas(user_id,province,district,subdistrict) VALUES(?,?,?,?)');
    try {
        $stmt->execute(array($user['id'],$province,$district,$subdistrict));
    } catch (PDOException $e) {
        json_response(array('ok'=>false,'message'=>'พื้นที่นี้มีอยู่แล้ว'),409);
    }

    json_response(array('ok'=>true,'id'=>db()->lastInsertId()));
}

if ($action === 'seller_area_delete') {
    $user = require_seller();
    $data = input_json();
    $id = (int)(isset($data['id'])?$data['id']:0);

    $stmt = db()->prepare('DELETE FROM seller_areas WHERE id=? AND user_id=?');
    $stmt->execute(array($id,$user['id']));
    json_response(array('ok'=>true));
}

if ($action === 'seller_route_save') {
    $user = require_seller();
    $data = input_json();

    $areaId = (int)(isset($data['area_id'])?$data['area_id']:0);
    $day = (int)(isset($data['day_of_week'])?$data['day_of_week']:0);
    $round = (int)(isset($data['round_no'])?$data['round_no']:0);

    if ($day < 1 || $day > 6) json_response(array('ok'=>false,'message'=>'วันต้องอยู่ระหว่างจันทร์ถึงเสาร์'),422);
    if ($round < 1) json_response(array('ok'=>false,'message'=>'รอบไม่ถูกต้อง'),422);

    $stmt = db()->prepare('SELECT id FROM seller_areas WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute(array($areaId,$user['id']));
    if (!$stmt->fetch()) json_response(array('ok'=>false,'message'=>'ไม่พบพื้นที่'),404);

    $stmt = db()->prepare('INSERT INTO seller_routes(seller_area_id,day_of_week,round_no) VALUES(?,?,?)');
    try {
        $stmt->execute(array($areaId,$day,$round));
    } catch (PDOException $e) {
        json_response(array('ok'=>false,'message'=>'รอบนี้มีอยู่แล้ว'),409);
    }

    json_response(array('ok'=>true));
}

if ($action === 'seller_route_delete') {
    $user = require_seller();
    $data = input_json();
    $id = (int)(isset($data['id'])?$data['id']:0);

    $stmt = db()->prepare(
        'DELETE sr FROM seller_routes sr
         INNER JOIN seller_areas sa ON sa.id=sr.seller_area_id
         WHERE sr.id=? AND sa.user_id=?'
    );
    $stmt->execute(array($id,$user['id']));
    json_response(array('ok'=>true));
}

if ($action === 'products') {
    $stmt = db()->query('SELECT id,name,price,image_url FROM products WHERE active=1 ORDER BY id');
    json_response(array('ok'=>true,'products'=>$stmt->fetchAll()));
}

if ($action === 'create_order') {
    $user = require_user();
    $data = input_json();

    $name = clean(isset($data['customer_name'])?$data['customer_name']:'');
    $phone = clean(isset($data['customer_phone'])?$data['customer_phone']:'');
    $province = clean(isset($data['province'])?$data['province']:'');
    $district = clean(isset($data['district'])?$data['district']:'');
    $subdistrict = clean(isset($data['subdistrict'])?$data['subdistrict']:'');
    $deliveryDate = clean(isset($data['delivery_date'])?$data['delivery_date']:'');
    $round = (int)(isset($data['round_no'])?$data['round_no']:0);
    $payment = clean(isset($data['payment_method'])?$data['payment_method']:'');
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
    $total = (float)(isset($data['total'])?$data['total']:0);

    if ($name==='' || $phone==='' || $province==='' || $district==='' || $subdistrict==='' || $deliveryDate==='' || $round<1 || $payment==='' || !$items) {
        json_response(array('ok'=>false,'message'=>'ข้อมูลคำสั่งซื้อไม่ครบ'),422);
    }

    $day = (int)date('N',strtotime($deliveryDate));

    if ($day < 1 || $day > 6) {
        json_response(array('ok'=>false,'message'=>'วันอาทิตย์ไม่สามารถเลือกวันวิ่งได้'),422);
    }

    $stmt = db()->prepare(
        'SELECT DISTINCT u.id,u.email
         FROM users u
         INNER JOIN seller_areas sa ON sa.user_id=u.id
         INNER JOIN seller_routes sr ON sr.seller_area_id=sa.id
         WHERE u.is_seller=1
         AND sa.province=?
         AND sa.district=?
         AND sa.subdistrict=?
         AND sr.day_of_week=?
         AND sr.round_no=?'
    );
    $stmt->execute(array($province,$district,$subdistrict,$day,$round));
    $sellers = $stmt->fetchAll();

    if (!$sellers) {
        json_response(array('ok'=>false,'message'=>'ยังไม่มีเซลล์วิ่งพื้นที่นี้ในวันที่และรอบที่เลือก'),409);
    }

    $pdo = db();
    $no = order_no();

    $stmt = $pdo->prepare(
        'INSERT INTO orders
        (order_no,customer_id,customer_email,customer_name,customer_phone,province,district,subdistrict,delivery_date,round_no,payment_method,total,items_json,status,matched_seller_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $stmt->execute(array(
        $no,
        $user['id'],
        $user['email'],
        $name,
        $phone,
        $province,
        $district,
        $subdistrict,
        $deliveryDate,
        $round,
        $payment,
        $total,
        json_encode($items,JSON_UNESCAPED_UNICODE),
        'pending',
        $sellers[0]['id']
    ));

    $subject = 'คำสั่งซื้อใหม่ '.$no;
    $body = "APP BETAGEN\n\n";
    $body .= "เลขที่คำสั่งซื้อ: ".$no."\n";
    $body .= "ลูกค้า: ".$name."\n";
    $body .= "อีเมล์: ".$user['email']."\n";
    $body .= "โทร: ".$phone."\n";
    $body .= "พื้นที่: ".$province." / ".$district." / ".$subdistrict."\n";
    $body .= "วันที่วิ่ง: ".$deliveryDate."\n";
    $body .= "รอบ: ".$round."\n";
    $body .= "ชำระเงิน: ".$payment."\n";
    $body .= "ยอดรวม: ".number_format($total,2)." บาท\n\n";
    $body .= "รายการสินค้า:\n";

    foreach ($items as $item) {
        $body .= '- '.clean(isset($item['name'])?$item['name']:'').' x '.(int)(isset($item['quantity'])?$item['quantity']:0).' = '.number_format((float)(isset($item['subtotal'])?$item['subtotal']:0),2)." บาท\n";
    }

    foreach ($sellers as $seller) {
        send_mail_text($seller['email'],$subject,$body);
    }

    json_response(array(
        'ok'=>true,
        'order_no'=>$no,
        'sent_to'=>array_map(function($s){return $s['email'];},$sellers)
    ));
}

if ($action === 'my_orders') {
    $user = require_user();
    $stmt = db()->prepare('SELECT * FROM orders WHERE customer_id=? ORDER BY id DESC');
    $stmt->execute(array($user['id']));
    json_response(array('ok'=>true,'orders'=>$stmt->fetchAll()));
}

json_response(array('ok'=>false,'message'=>'ไม่พบคำสั่ง'),404);
