<?php

require_once __DIR__.'/config.php';

function random_otp() {
    return str_pad(
        (string)random_int(0, 999999),
        6,
        '0',
        STR_PAD_LEFT
    );
}

function valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function order_no() {
    return 'BT'
        .date('ymdHis')
        .str_pad(
            (string)random_int(0, 999),
            3,
            '0',
            STR_PAD_LEFT
        );
}

function send_otp($email, $purpose) {
    $pdo = db();

    $code = random_otp();

    $hash = password_hash(
        $code,
        PASSWORD_DEFAULT
    );

    $expires = date(
        'Y-m-d H:i:s',
        time() + (OTP_EXPIRE_MINUTES * 60)
    );

    $stmt = $pdo->prepare(
        'DELETE FROM otp_codes
         WHERE email=? AND purpose=?'
    );

    $stmt->execute(array(
        $email,
        $purpose
    ));

    $stmt = $pdo->prepare(
        'INSERT INTO otp_codes
        (email,code_hash,purpose,expires_at,attempts)
        VALUES(?,?,?,?,0)'
    );

    $stmt->execute(array(
        $email,
        $hash,
        $purpose,
        $expires
    ));

    $subject = 'รหัส OTP สำหรับ APP BETAGEN';

    $body =
        "APP BETAGEN\n\n".
        "รหัส OTP ของคุณคือ: ".$code."\n\n".
        "รหัสนี้หมดอายุภายใน ".OTP_EXPIRE_MINUTES." นาที\n\n".
        "หากคุณไม่ได้เป็นผู้ร้องขอ สามารถละเว้นอีเมลนี้ได้";

    return send_mail_text(
        $email,
        $subject,
        $body
    );
}

function verify_otp($email, $code, $purpose) {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id,code_hash,expires_at,attempts
         FROM otp_codes
         WHERE email=? AND purpose=?
         ORDER BY id DESC
         LIMIT 1'
    );

    $stmt->execute(array(
        $email,
        $purpose
    ));

    $otp = $stmt->fetch();

    if (!$otp) {
        return false;
    }

    if (strtotime($otp['expires_at']) < time()) {
        return false;
    }

    if ((int)$otp['attempts'] >= 5) {
        return false;
    }

    if (!password_verify($code, $otp['code_hash'])) {
        $stmt = $pdo->prepare(
            'UPDATE otp_codes
             SET attempts=attempts+1
             WHERE id=?'
        );

        $stmt->execute(array(
            (int)$otp['id']
        ));

        return false;
    }

    $stmt = $pdo->prepare(
        'DELETE FROM otp_codes
         WHERE id=?'
    );

    $stmt->execute(array(
        (int)$otp['id']
    ));

    return true;
}

$data = input_json();

$action = isset($data['action'])
    ? clean($data['action'])
    : '';

if ($action === 'register_request') {

    $email = strtolower(
        clean(isset($data['email']) ? $data['email'] : '')
    );

    $password = isset($data['password'])
        ? (string)$data['password']
        : '';

    if (!valid_email($email)) {
        json_response(array(
            'ok' => false,
            'message' => 'รูปแบบอีเมลไม่ถูกต้อง'
        ), 422);
    }

    if (strlen($password) < 6) {
        json_response(array(
            'ok' => false,
            'message' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร'
        ), 422);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE email=?
         LIMIT 1'
    );

    $stmt->execute(array(
        $email
    ));

    if ($stmt->fetch()) {
        json_response(array(
            'ok' => false,
            'message' => 'อีเมลนี้มีบัญชีอยู่แล้ว'
        ), 409);
    }

    $_SESSION['register_email'] = $email;

    $_SESSION['register_password_hash'] =
        password_hash(
            $password,
            PASSWORD_DEFAULT
        );

    $sent = send_otp(
        $email,
        'register'
    );

    if (!$sent) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่สามารถส่ง OTP ได้ กรุณาตรวจสอบการตั้งค่าอีเมลของเซิร์ฟเวอร์'
        ), 500);
    }

    json_response(array(
        'ok' => true,
        'message' => 'ส่งรหัส OTP ไปยังอีเมลแล้ว'
    ));
}

if ($action === 'register_verify') {

    $email = strtolower(
        clean(isset($data['email']) ? $data['email'] : '')
    );

    $code = clean(
        isset($data['code'])
            ? $data['code']
            : ''
    );

    if (!valid_email($email)) {
        json_response(array(
            'ok' => false,
            'message' => 'อีเมลไม่ถูกต้อง'
        ), 422);
    }

    if (!preg_match('/^[0-9]{6}$/', $code)) {
        json_response(array(
            'ok' => false,
            'message' => 'รหัส OTP ต้องเป็นตัวเลข 6 หลัก'
        ), 422);
    }

    if (
        empty($_SESSION['register_email']) ||
        $_SESSION['register_email'] !== $email
    ) {
        json_response(array(
            'ok' => false,
            'message' => 'เซสชันสมัครสมาชิกหมดอายุ กรุณาเริ่มใหม่'
        ), 400);
    }

    if (
        empty($_SESSION['register_password_hash'])
    ) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่พบข้อมูลการสมัครสมาชิก'
        ), 400);
    }

    if (!verify_otp(
        $email,
        $code,
        'register'
    )) {
        json_response(array(
            'ok' => false,
            'message' => 'OTP ไม่ถูกต้องหรือหมดอายุ'
        ), 422);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE email=?
         LIMIT 1'
    );

    $stmt->execute(array(
        $email
    ));

    if ($stmt->fetch()) {
        unset(
            $_SESSION['register_email'],
            $_SESSION['register_password_hash']
        );

        json_response(array(
            'ok' => false,
            'message' => 'อีเมลนี้มีบัญชีอยู่แล้ว'
        ), 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users
        (email,password_hash,is_seller)
        VALUES(?,?,0)'
    );

    $stmt->execute(array(
        $email,
        $_SESSION['register_password_hash']
    ));

    $userId = (int)$pdo->lastInsertId();

    unset(
        $_SESSION['register_email'],
        $_SESSION['register_password_hash']
    );

    $_SESSION['user_id'] = $userId;

    $user = current_user();

    json_response(array(
        'ok' => true,
        'message' => 'สมัครสมาชิกสำเร็จ',
        'user' => $user
    ));
}

if ($action === 'login') {

    $email = strtolower(
        clean(isset($data['email']) ? $data['email'] : '')
    );

    $password = isset($data['password'])
        ? (string)$data['password']
        : '';

    if (!valid_email($email)) {
        json_response(array(
            'ok' => false,
            'message' => 'อีเมลไม่ถูกต้อง'
        ), 422);
    }

    if ($password === '') {
        json_response(array(
            'ok' => false,
            'message' => 'กรุณากรอกรหัสผ่าน'
        ), 422);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id,email,password_hash,is_seller,created_at
         FROM users
         WHERE email=?
         LIMIT 1'
    );

    $stmt->execute(array(
        $email
    ));

    $user = $stmt->fetch();

    if (!$user) {
        json_response(array(
            'ok' => false,
            'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
        ), 401);
    }

    if (!password_verify(
        $password,
        $user['password_hash']
    )) {
        json_response(array(
            'ok' => false,
            'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
        ), 401);
    }

    $_SESSION['user_id'] = (int)$user['id'];

    $user = current_user();

    json_response(array(
        'ok' => true,
        'message' => 'เข้าสู่ระบบสำเร็จ',
        'user' => $user
    ));
}

if ($action === 'logout') {

    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    json_response(array(
        'ok' => true,
        'message' => 'ออกจากระบบแล้ว'
    ));
}

if ($action === 'me') {

    $user = current_user();

    json_response(array(
        'ok' => true,
        'user' => $user
    ));
}

if ($action === 'enable_seller') {

    $user = require_user();

    $pdo = db();

    $stmt = $pdo->prepare(
        'UPDATE users
         SET is_seller=1
         WHERE id=?'
    );

    $stmt->execute(array(
        (int)$user['id']
    ));

    $user = current_user();

    json_response(array(
        'ok' => true,
        'message' => 'เปิดใช้งาน Seller สำเร็จ',
        'user' => $user
    ));
}

if ($action === 'seller_areas') {

    $user = require_seller();

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT
            id,
            province,
            district,
            subdistrict,
            created_at
         FROM seller_areas
         WHERE user_id=?
         ORDER BY id DESC'
    );

    $stmt->execute(array(
        (int)$user['id']
    ));

    $areas = $stmt->fetchAll();

    foreach ($areas as &$area) {
        $area['id'] = (int)$area['id'];
    }

    json_response(array(
        'ok' => true,
        'areas' => $areas
    ));
}

if ($action === 'seller_area_save') {

    $user = require_seller();

    $province = clean(
        isset($data['province'])
            ? $data['province']
            : ''
    );

    $district = clean(
        isset($data['district'])
            ? $data['district']
            : ''
    );

    $subdistrict = clean(
        isset($data['subdistrict'])
            ? $data['subdistrict']
            : ''
    );

    if (
        $province === '' ||
        $district === '' ||
        $subdistrict === ''
    ) {
        json_response(array(
            'ok' => false,
            'message' => 'กรุณากรอกพื้นที่ให้ครบ'
        ), 422);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id
         FROM seller_areas
         WHERE user_id=?
         AND province=?
         AND district=?
         AND subdistrict=?
         LIMIT 1'
    );

    $stmt->execute(array(
        (int)$user['id'],
        $province,
        $district,
        $subdistrict
    ));

    if ($stmt->fetch()) {
        json_response(array(
            'ok' => false,
            'message' => 'พื้นที่นี้มีอยู่แล้ว'
        ), 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO seller_areas
        (user_id,province,district,subdistrict)
        VALUES(?,?,?,?)'
    );

    $stmt->execute(array(
        (int)$user['id'],
        $province,
        $district,
        $subdistrict
    ));

    json_response(array(
        'ok' => true,
        'message' => 'เพิ่มพื้นที่สำเร็จ',
        'id' => (int)$pdo->lastInsertId()
    ));
}

if ($action === 'seller_area_delete') {

    $user = require_seller();

    $areaId = (int)(
        isset($data['area_id'])
            ? $data['area_id']
            : 0
    );

    if ($areaId <= 0) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่พบพื้นที่'
        ), 422);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'DELETE FROM seller_areas
         WHERE id=? AND user_id=?'
    );

    $stmt->execute(array(
        $areaId,
        (int)$user['id']
    ));

    if ($stmt->rowCount() === 0) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่พบพื้นที่หรือไม่มีสิทธิ์ลบ'
        ), 404);
    }

    json_response(array(
        'ok' => true,
        'message' => 'ลบพื้นที่สำเร็จ'
    ));
}

if ($action === 'seller_route_save') {

    $user = require_seller();

    $areaId = (int)(
        isset($data['area_id'])
            ? $data['area_id']
            : 0
    );

    $dayOfWeek = (int)(
        isset($data['day_of_week'])
            ? $data['day_of_week']
            : 0
    );

    $roundNo = (int)(
        isset($data['round_no'])
            ? $data['round_no']
            : 0
    );

    if ($areaId <= 0) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่พบพื้นที่'
        ), 422);
    }

    if ($dayOfWeek < 1 || $dayOfWeek > 6) {
        json_response(array(
            'ok' => false,
            'message' => 'วันไม่ถูกต้อง'
        ), 422);
    }

    if ($roundNo < 1 || $roundNo > 5) {
        json_response(array(
            'ok' => false,
            'message' => 'รอบไม่ถูกต้อง'
        ), 422);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id
         FROM seller_areas
         WHERE id=? AND user_id=?
         LIMIT 1'
    );

    $stmt->execute(array(
        $areaId,
        (int)$user['id']
    ));

    if (!$stmt->fetch()) {
        json_response(array(
            'ok' => false,
            'message' => 'พื้นที่นี้ไม่ใช่พื้นที่ของคุณ'
        ), 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM seller_routes
         WHERE area_id=?
         AND day_of_week=?
         AND round_no=?
         LIMIT 1'
    );

    $stmt->execute(array(
        $areaId,
        $dayOfWeek,
        $roundNo
    ));

    if ($stmt->fetch()) {
        json_response(array(
            'ok' => false,
            'message' => 'เส้นทางนี้มีอยู่แล้ว'
        ), 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO seller_routes
        (area_id,day_of_week,round_no)
        VALUES(?,?,?)'
    );

    $stmt->execute(array(
        $areaId,
        $dayOfWeek,
        $roundNo
    ));

    json_response(array(
        'ok' => true,
        'message' => 'บันทึกรอบส่งสำเร็จ',
        'id' => (int)$pdo->lastInsertId()
    ));
}

if ($action === 'seller_route_delete') {

    $user = require_seller();

    $routeId = (int)(
        isset($data['route_id'])
            ? $data['route_id']
            : 0
    );

    if ($routeId <= 0) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่พบเส้นทาง'
        ), 422);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'DELETE sr
         FROM seller_routes sr
         INNER JOIN seller_areas sa
         ON sa.id=sr.area_id
         WHERE sr.id=?
         AND sa.user_id=?'
    );

    $stmt->execute(array(
        $routeId,
        (int)$user['id']
    ));

    if ($stmt->rowCount() === 0) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่พบเส้นทางหรือไม่มีสิทธิ์ลบ'
        ), 404);
    }

    json_response(array(
        'ok' => true,
        'message' => 'ลบเส้นทางสำเร็จ'
    ));
}

if ($action === 'products') {

    $pdo = db();

    $stmt = $pdo->query(
        'SELECT
            id,
            name,
            price,
            image_url,
            active
         FROM products
         WHERE active=1
         ORDER BY id ASC'
    );

    $products = $stmt->fetchAll();

    foreach ($products as &$product) {
        $product['id'] = (int)$product['id'];
        $product['price'] = (float)$product['price'];
        $product['active'] = (int)$product['active'];
    }

    json_response(array(
        'ok' => true,
        'products' => $products
    ));
}

if ($action === 'create_order') {

    $user = require_user();

    $customerName = clean(
        isset($data['customer_name'])
            ? $data['customer_name']
            : ''
    );

    $phone = clean(
        isset($data['phone'])
            ? $data['phone']
            : ''
    );

    $address = clean(
        isset($data['address'])
            ? $data['address']
            : ''
    );

    $province = clean(
        isset($data['province'])
            ? $data['province']
            : ''
    );

    $district = clean(
        isset($data['district'])
            ? $data['district']
            : ''
    );

    $subdistrict = clean(
        isset($data['subdistrict'])
            ? $data['subdistrict']
            : ''
    );

    $deliveryDate = clean(
        isset($data['delivery_date'])
            ? $data['delivery_date']
            : ''
    );

    $roundNo = (int)(
        isset($data['round_no'])
            ? $data['round_no']
            : 0
    );

    $payment = clean(
        isset($data['payment'])
            ? $data['payment']
            : ''
    );

    $items = isset($data['items'])
        ? $data['items']
        : array();

    if (
        $customerName === '' ||
        $phone === '' ||
        $address === '' ||
        $province === '' ||
        $district === '' ||
        $subdistrict === ''
    ) {
        json_response(array(
            'ok' => false,
            'message' => 'กรุณากรอกข้อมูลจัดส่งให้ครบ'
        ), 422);
    }

    if (!preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) {
        json_response(array(
            'ok' => false,
            'message' => 'เบอร์โทรศัพท์ไม่ถูกต้อง'
        ), 422);
    }

    if (
        $deliveryDate === '' ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)
    ) {
        json_response(array(
            'ok' => false,
            'message' => 'วันที่จัดส่งไม่ถูกต้อง'
        ), 422);
    }

    $timestamp = strtotime($deliveryDate);

    if (
        $timestamp === false ||
        date('Y-m-d', $timestamp) !== $deliveryDate
    ) {
        json_response(array(
            'ok' => false,
            'message' => 'วันที่จัดส่งไม่ถูกต้อง'
        ), 422);
    }

    if ($deliveryDate < date('Y-m-d')) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่สามารถเลือกวันที่ผ่านมาแล้วได้'
        ), 422);
    }

    $dayOfWeek = (int)date(
        'N',
        $timestamp
    );

    if ($dayOfWeek === 7) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่สามารถจัดส่งวันอาทิตย์ได้'
        ), 422);
    }

    if ($roundNo < 1 || $roundNo > 5) {
        json_response(array(
            'ok' => false,
            'message' => 'รอบจัดส่งไม่ถูกต้อง'
        ), 422);
    }

    if (
        $payment !== 'cod' &&
        $payment !== 'transfer'
    ) {
        json_response(array(
            'ok' => false,
            'message' => 'รูปแบบการชำระเงินไม่ถูกต้อง'
        ), 422);
    }

    if (!is_array($items) || count($items) === 0) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่มีสินค้าในคำสั่งซื้อ'
        ), 422);
    }

    $quantities = array();

    foreach ($items as $item) {

        if (!is_array($item)) {
            continue;
        }

        $productId = (int)(
            isset($item['product_id'])
                ? $item['product_id']
                : 0
        );

        $quantity = (int)(
            isset($item['quantity'])
                ? $item['quantity']
                : 0
        );

        if ($productId <= 0) {
            json_response(array(
                'ok' => false,
                'message' => 'พบรหัสสินค้าไม่ถูกต้อง'
            ), 422);
        }

        if ($quantity <= 0 || $quantity > 999) {
            json_response(array(
                'ok' => false,
                'message' => 'จำนวนสินค้าไม่ถูกต้อง'
            ), 422);
        }

        if (!isset($quantities[$productId])) {
            $quantities[$productId] = 0;
        }

        $quantities[$productId] += $quantity;

        if ($quantities[$productId] > 999) {
            json_response(array(
                'ok' => false,
                'message' => 'จำนวนสินค้าสูงเกินกำหนด'
            ), 422);
        }
    }

    if (count($quantities) === 0) {
        json_response(array(
            'ok' => false,
            'message' => 'ไม่พบสินค้า'
        ), 422);
    }

    $pdo = db();

    $ids = array_keys($quantities);

    $placeholders = implode(
        ',',
        array_fill(
            0,
            count($ids),
            '?'
        )
    );

    $stmt = $pdo->prepare(
        'SELECT id,name,price
         FROM products
         WHERE active=1
         AND id IN ('.$placeholders.')'
    );

    $stmt->execute($ids);

    $productRows = $stmt->fetchAll();

    $productsById = array();

    foreach ($productRows as $row) {
        $productsById[(int)$row['id']] = $row;
    }

    if (count($productsById) !== count($quantities)) {
        json_response(array(
            'ok' => false,
            'message' => 'มีสินค้าบางรายการไม่พร้อมจำหน่าย'
        ), 422);
    }

    $serverItems = array();
    $total = 0;

    foreach ($quantities as $productId => $quantity) {

        $row = $productsById[$productId];

        $price = (float)$row['price'];

        $lineTotal = $price * $quantity;

        $total += $lineTotal;

        $serverItems[] = array(
            'product_id' => (int)$productId,
            'name' => $row['name'],
            'quantity' => (int)$quantity,
            'price' => $price,
            'total' => $lineTotal
        );
    }

    $stmt = $pdo->prepare(
        'SELECT
            sa.id AS area_id,
            sa.user_id AS seller_id,
            sr.id AS route_id
         FROM seller_areas sa
         INNER JOIN seller_routes sr
         ON sr.area_id=sa.id
         WHERE sa.province=?
         AND sa.district=?
         AND sa.subdistrict=?
         AND sr.day_of_week=?
         AND sr.round_no=?
         ORDER BY sa.id ASC
         LIMIT 50'
    );

    $stmt->execute(array(
        $province,
        $district,
        $subdistrict,
        $dayOfWeek,
        $roundNo
    ));

    $sellers = $stmt->fetchAll();

    $matchedSellerId = null;

    if (count($sellers) > 0) {
        $matchedSellerId = (int)$sellers[0]['seller_id'];
    }

    $orderNo = order_no();

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare(
            'INSERT INTO orders
            (
                order_no,
                customer_id,
                matched_seller_id,
                customer_name,
                phone,
                address,
                province,
                district,
                subdistrict,
                delivery_date,
                day_of_week,
                round_no,
                payment,
                items_json,
                total_amount,
                status
            )
            VALUES
            (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $stmt->execute(array(
            $orderNo,
            (int)$user['id'],
            $matchedSellerId,
            $customerName,
            $phone,
            $address,
            $province,
            $district,
            $subdistrict,
            $deliveryDate,
            $dayOfWeek,
            $roundNo,
            $payment,
            json_encode(
                $serverItems,
                JSON_UNESCAPED_UNICODE
            ),
            $total,
            'pending'
        ));

        $orderId = (int)$pdo->lastInsertId();

        $pdo->commit();

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response(array(
            'ok' => false,
            'message' => 'ไม่สามารถสร้างคำสั่งซื้อได้'
        ), 500);
    }

    $sellerEmails = array();

    if (count($sellers) > 0) {

        $sellerIds = array();

        foreach ($sellers as $seller) {
            $sellerIds[] = (int)$seller['seller_id'];
        }

        $sellerIds = array_unique($sellerIds);

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($sellerIds),
                '?'
            )
        );

        $stmt = $pdo->prepare(
            'SELECT email
             FROM users
             WHERE id IN ('.$placeholders.')'
        );

        $stmt->execute($sellerIds);

        $sellerEmails = $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );
    }

    $emailBody =
        "มีคำสั่งซื้อใหม่จาก APP BETAGEN\n\n".
        "เลขคำสั่งซื้อ: ".$orderNo."\n".
        "ลูกค้า: ".$customerName."\n".
        "เบอร์โทร: ".$phone."\n".
        "ที่อยู่: ".$address."\n".
        "จังหวัด: ".$province."\n".
        "อำเภอ: ".$district."\n".
        "ตำบล: ".$subdistrict."\n".
        "วันที่จัดส่ง: ".$deliveryDate."\n".
        "รอบ: ".$roundNo."\n".
        "การชำระเงิน: ".$payment."\n\n".
        "สินค้า:\n";

    foreach ($serverItems as $item) {
        $emailBody .=
            "- ".$item['name'].
            " x ".$item['quantity'].
            " = ".number_format(
                $item['total'],
                2
            )." บาท\n";
    }

    $emailBody .=
        "\nยอดรวม: ".
        number_format(
            $total,
            2
        ).
        " บาท\n";

    foreach ($sellerEmails as $sellerEmail) {

        send_mail_text(
            $sellerEmail,
            'มีคำสั่งซื้อใหม่ #'.$orderNo,
            $emailBody
        );
    }

    json_response(array(
        'ok' => true,
        'message' => 'สร้างคำสั่งซื้อสำเร็จ',
        'order_id' => $orderId,
        'order_no' => $orderNo,
        'total' => $total,
        'seller_found' => count($sellers) > 0
    ));
}

if ($action === 'my_orders') {

    $user = require_user();

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT
            id,
            order_no,
            customer_name,
            phone,
            address,
            province,
            district,
            subdistrict,
            delivery_date,
            day_of_week,
            round_no,
            payment,
            items_json,
            total_amount,
            status,
            created_at
         FROM orders
         WHERE customer_id=?
         ORDER BY id DESC'
    );

    $stmt->execute(array(
        (int)$user['id']
    ));

    $orders = $stmt->fetchAll();

    foreach ($orders as &$order) {

        $order['id'] = (int)$order['id'];
        $order['day_of_week'] = (int)$order['day_of_week'];
        $order['round_no'] = (int)$order['round_no'];
        $order['total_amount'] = (float)$order['total_amount'];

        $decoded = json_decode(
            $order['items_json'],
            true
        );

        $order['items'] = is_array($decoded)
            ? $decoded
            : array();

        unset($order['items_json']);
    }

    json_response(array(
        'ok' => true,
        'orders' => $orders
    ));
}

json_response(array(
    'ok' => false,
    'message' => 'ไม่พบคำสั่ง'
), 404);
