<?php
require_once 'db.php';
require_once 'session.php';
require_once 'lockout.php';
require_once 'security.php';
$v = seed_values();
$msg = '';
$captcha = $_SESSION['captcha_challenge']['display'] ?? captcha_new();
$old = [];
// Question bank (handout section 5). The user picks three DIFFERENT questions from it.
$questionBank = [
    1 => 'What was the name of your first pet?',
    2 => 'Who was your best friend in elementary school?',
    3 => 'Who was your favorite teacher in high school?',
    4 => 'What is your mother’s maiden first name?',
    5 => 'What street did you live on in Grade 1?',
    6 => 'What was the first song you learned by heart?',
    7 => 'What was the name of your elementary school?',
    8 => 'What dish did you cook for the first time?',
];
function gc_name_valid(string $value, int $max, bool $optional = false): bool
{
    $value = trim($value);
    if ($optional && $value === '') {
        return true;
    }
    return strlen($value) >= 2 &&
        strlen($value) <= $max &&
        preg_match('/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/', $value) &&
        !preg_match('/(.)\1\1/i', $value);
}
function gc_words_valid(string $value, int $max): bool
{
    $value = trim($value);
    return strlen($value) >= 2 &&
        strlen($value) <= $max &&
        preg_match('/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/', $value) &&
        !preg_match('/(.)\1\1/i', $value);
}
function gc_answer_valid(string $value): bool
{
    return (bool) preg_match('/^(?=.{2,50}$)[A-Za-z]+(?: [A-Za-z]+)*$/', trim($value));
}
function gc_age(string $birth): ?int
{
    $d = DateTime::createFromFormat('Y-m-d', $birth);
    if (!$d || $d->format('Y-m-d') !== $birth) {
        return null;
    }
    $today = new DateTime('today');
    if ($d > $today) {
        return null;
    }
    return (int) $d->diff($today)->y;
}
function gc_ensure_registration_schema(mysqli $conn): bool
{
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM users");
    if (!($result instanceof mysqli_result)) {
        $conn->query("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, id_number VARCHAR(9) NULL, first_name VARCHAR(50) NULL, middle_name VARCHAR(50) NULL, family_name VARCHAR(50) NULL, name_extension VARCHAR(20) NULL, birthdate DATE NULL, age TINYINT UNSIGNED NULL, purok VARCHAR(50) NULL, barangay VARCHAR(50) NULL, city VARCHAR(50) NULL, province VARCHAR(50) NULL, country VARCHAR(50) NULL, zip_code CHAR(4) NULL, username VARCHAR(191) NOT NULL UNIQUE, email VARCHAR(100) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role ENUM('user','admin') NOT NULL DEFAULT 'user', consent_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, verified_at DATETIME NULL)");
        $result = $conn->query("SHOW COLUMNS FROM users");
    }
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) { $columns[$row['Field']] = true; }
        $result->free();
    }
    $needed = [
        'id_number' => 'VARCHAR(9) NULL', 'first_name' => 'VARCHAR(50) NULL',
        'middle_name' => 'VARCHAR(50) NULL', 'family_name' => 'VARCHAR(50) NULL',
        'name_extension' => 'VARCHAR(20) NULL', 'birthdate' => 'DATE NULL',
        'age' => 'TINYINT UNSIGNED NULL', 'purok' => 'VARCHAR(50) NULL',
        'barangay' => 'VARCHAR(50) NULL', 'city' => 'VARCHAR(50) NULL',
        'province' => 'VARCHAR(50) NULL', 'country' => 'VARCHAR(50) NULL',
        'zip_code' => 'CHAR(4) NULL', 'consent_at' => 'DATETIME NULL',
        'verified_at' => 'DATETIME NULL'
    ];
    foreach ($needed as $name => $definition) {
        if (!isset($columns[$name]) && $conn->query("ALTER TABLE users ADD COLUMN `$name` $definition") === false) {
            error_log('Could not add users.' . $name . ' (' . $conn->errno . '): ' . $conn->error);
            return false;
        }
    }
    $conn->query("ALTER TABLE users MODIFY first_name VARCHAR(50) NOT NULL, MODIFY middle_name VARCHAR(50) NULL, MODIFY family_name VARCHAR(50) NOT NULL");
    if ($conn->query("CREATE TABLE IF NOT EXISTS security_answers (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, question_no TINYINT NOT NULL, answer_hash CHAR(64) NOT NULL, UNIQUE KEY uq_user_question(user_id,question_no))") === false) {
        error_log('Could not create security_answers (' . $conn->errno . '): ' . $conn->error);
        return false;
    }
    return true;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $msg = 'Your form expired. Please try again.';
        log_event($conn, 'CSRF_REJECTED');
    } elseif (!captcha_valid($_POST['captcha'] ?? null)) {
        $msg = 'Please complete the CAPTCHA correctly.';
        log_event($conn, 'CAPTCHA_FAILED');
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        exit();
    } elseif (!isset($_POST['consent'])) {
        $msg = 'You must agree to the Privacy Notice to register.';
    } else {
        $idno = trim((string) ($_POST['id_number'] ?? ''));
        $first = trim((string) ($_POST['first_name'] ?? ''));
        $middle = trim((string) ($_POST['middle_name'] ?? ''));
        $family = trim((string) ($_POST['family_name'] ?? ''));
        $ext = trim((string) ($_POST['name_extension'] ?? ''));
        $extOther = trim((string) ($_POST['extension_other'] ?? ''));
        $birth = trim((string) ($_POST['birthdate'] ?? ''));
        $purok = trim((string) ($_POST['purok'] ?? ''));
        $barangay = trim((string) ($_POST['barangay'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $province = trim((string) ($_POST['province'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        $zip = trim((string) ($_POST['zip_code'] ?? ''));
        $u = trim((string) ($_POST['username'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $p = (string) ($_POST['password'] ?? '');
        $c = (string) ($_POST['confirm'] ?? '');
        $chosen = [];
        $answers = [];
        foreach ([1, 2, 3] as $slot) {
            $chosen[$slot] = (int) ($_POST['question_' . $slot] ?? 0);
            $answers[$slot] = trim((string) ($_POST['answer_' . $slot] ?? ''));
        }
        $age = gc_age($birth);
        if (!preg_match('/^\d{4}-\d{4}$/', $idno)) {
            $msg = 'ID Number must follow the format xxxx-xxxx.';
        } elseif (!gc_name_valid($first, $v['max_name'])) {
            $msg =
                'First Name must use Title Case letters only, with no double spaces or three repeated letters.';
        } elseif (!gc_name_valid($middle, $v['max_name'], true)) {
            $msg =
                'Middle Name must use Title Case letters only, with no double spaces or three repeated letters.';
        } elseif (!gc_name_valid($family, $v['max_name'])) {
            $msg =
                'Family Name must use Title Case letters only, with no double spaces or three repeated letters.';
        } elseif (!in_array($ext, ['', 'Jr.', 'Sr.', 'Other'], true)) {
            $msg = 'Please select a valid Name Extension.';
        } elseif (
            $ext === 'Other' &&
            !in_array($extOther, ['I', 'II', 'III', 'IV', 'IX', 'X'], true)
        ) {
            $msg = 'Name Extension Other must be I, II, III, IV, IX, or X.';
        } elseif ($age === null) {
            $msg = 'Birthdate must be a valid date that is not in the future.';
        } elseif ($age < 18) {
            $msg = 'You must be at least 18 years old to register.';
        } elseif (!preg_match('/^(?=.{3,50}$)[A-Za-z0-9]+(?: [A-Za-z0-9]+)*$/', $purok)) {
            $msg = 'Purok / Street must be 3-50 letters, digits and spaces with no double spaces.';
        } elseif (!gc_words_valid($barangay, 50)) {
            $msg = 'Barangay must be Title Case letters and spaces, 2-50 characters.';
        } elseif (!gc_words_valid($city, 50)) {
            $msg = 'Municipality / City must be Title Case letters and spaces, 2-50 characters.';
        } elseif (!gc_words_valid($province, 50)) {
            $msg = 'Province must be Title Case letters and spaces, 2-50 characters.';
        } elseif (!gc_words_valid($country, 50)) {
            $msg = 'Country must be Title Case letters and spaces.';
        } elseif (!preg_match('/^\d{4}$/', $zip)) {
            $msg = 'ZIP Code must be exactly 4 digits.';
        } elseif (
            !preg_match(
                '/^[A-Za-z][A-Za-z0-9]{' .
                    ($v['min_user'] - 1) .
                    ',' .
                    ($v['max_user'] - 1) .
                    '}$/',
                $u,
            )
        ) {
            $msg =
                'Username must be ' .
                $v['min_user'] .
                '-' .
                $v['max_user'] .
                ' characters, start with a letter, and contain only letters and numbers.';
        } elseif (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 100 ||
            preg_match('/\s/', $email)
        ) {
            $msg = 'Please enter a valid email address.';
        } elseif (strlen($p) < $v['min_pass'] || strlen($p) > 64) {
            $msg = 'Password must be ' . $v['min_pass'] . ' to 64 characters.';
        } elseif ($p !== $c) {
            $msg = 'Passwords do not match.';
        } elseif (
            !preg_match('/[A-Z]/', $p) ||
            !preg_match('/[a-z]/', $p) ||
            !preg_match('/[0-9]/', $p)
        ) {
            $msg = 'Password must include uppercase, lowercase, and a number.';
        } elseif (
            count(array_filter($chosen, static fn($q) => isset($questionBank[$q]))) !== 3
        ) {
            $msg = 'Please choose three authentication questions from the list.';
        } elseif (count(array_unique($chosen)) !== 3) {
            $msg = 'Please choose three different authentication questions.';
        } elseif (
            !gc_answer_valid($answers[1]) ||
            !gc_answer_valid($answers[2]) ||
            !gc_answer_valid($answers[3])
        ) {
            $msg = 'Security answers must be 2-50 letters and spaces only, with no double spaces.';
        } else {
            $storedExt = $ext === 'Other' ? $extOther : $ext;
            $hash = password_hash($p, PASSWORD_DEFAULT);
            $consent = date('Y-m-d H:i:s');
            if (!gc_ensure_registration_schema($conn)) {
                $msg =
                    'Registration is unavailable because the database schema is outdated. Please ask the administrator to update it.';
            } else {
                $s = $conn->prepare(
                    'INSERT INTO users(id_number,first_name,middle_name,family_name,name_extension,birthdate,age,purok,barangay,city,province,country,zip_code,username,email,password,consent_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                );
                if (!$s) {
                    error_log(
                        'Registration insert prepare failed (' .
                            $conn->errno .
                            '): ' .
                            $conn->error,
                    );
                    $msg =
                        'Registration could not be saved because the database schema is not ready. Please contact the administrator.';
                } else {
                    $s->bind_param(
                        'ssssssissssssssss',
                        $idno,
                        $first,
                        $middle,
                        $family,
                        $storedExt,
                        $birth,
                        $age,
                        $purok,
                        $barangay,
                        $city,
                        $province,
                        $country,
                        $zip,
                        $u,
                        $email,
                        $hash,
                        $consent,
                    );
                    if ($s->execute()) {
                        $id = (int) $s->insert_id;
                        $s->close();
                        foreach ([1, 2, 3] as $slot) {
                            $q = $chosen[$slot];
                            $ans = $answers[$slot];
                            $ah = hash('sha256', mb_strtolower($ans));
                            $x = $conn->prepare(
                                'INSERT INTO security_answers(user_id,question_no,answer_hash) VALUES(?,?,?)',
                            );
                            if ($x) {
                                $x->bind_param('iis', $id, $q, $ah);
                                $x->execute();
                                $x->close();
                            } else {
                                error_log('Security answer insert prepare failed (' . $conn->errno . '): ' . $conn->error);
                            }
                        }
                        log_event($conn, 'REGISTERED', $u, $id, '');
                        issue_otp($conn, $id, 'register', $email, 'Verify your account');
                        $_SESSION['pending_register'] = $id;
                        header('Location: verify.php');
                        exit();
                    }
                    $msg =
                        $s->errno === 1062
                            ? 'This ID Number, username, or email is already registered.'
                            : 'Registration failed. Please try again.';
                    error_log('Registration failed (' . $s->errno . '): ' . $s->error);
                    $s->close();
                }
            }
        }
    }
}
$captcha = $_SESSION['captcha_challenge']['display'] ?? captcha_new();
unset(
    $old['password'],
    $old['confirm'],
    $old['captcha'],
    $old['answer_1'],
    $old['answer_2'],
    $old['answer_3'],
    $old['csrf'],
);
function oldv(string $key, array $old): string
{
    return e((string) ($old[$key] ?? ''));
}
?>
<?php
require_once __DIR__ . '/public_layout.php';
gc_pub_head('GoCycle | Register', 'register', true, 'is-auth');
?>
<main class="gc-stage is-wide">
    <section class="gc-welcome">
        <h1>Join GoCycle!</h1>
        <p>Let’s ride together.</p>
        <span class="streak" aria-hidden="true"></span>
        <ul class="gc-perks">
            <li><?php echo gc_pub_icon(
                'user',
                22,
            ); ?><span>Create your account in four short steps</span></li>
            <li><?php echo gc_pub_icon(
                'shield',
                22,
            ); ?><span>Your data is protected (RA 10173)</span></li>
            <li><?php echo gc_pub_icon(
                'mail',
                22,
            ); ?><span>Verify by email, then start booking</span></li>
        </ul>
    </section>

    <section class="gc-card" aria-labelledby="card-title">
        <div class="gc-badge"><?php echo gc_pub_icon('bike', 34); ?></div>
        <h2 id="card-title">Register</h2>
        <p class="gc-sub">Create your GoCycle account</p>

        <p class="form-message<?php echo $msg !== '' ? ' is-error' : ''; ?>" role="alert" data-form-message><?php echo e($msg); ?></p>

        <div class="wizard-progress">
            <span class="wizard-step active" data-progress="1"><i>1</i>Identity</span>
            <span class="wizard-step" data-progress="2"><i>2</i>Address</span>
            <span class="wizard-step" data-progress="3"><i>3</i>Password</span>
            <span class="wizard-step" data-progress="4"><i>4</i>Verify</span>
        </div>

        <form method="post" class="gc-form" novalidate data-server-error="<?php echo $msg !== '' ? '1' : ''; ?>" data-persist-key="gocycle-registration-draft"
            data-min-user="<?php echo (int) $v['min_user']; ?>" data-max-user="<?php echo (int) $v[
    'max_user'
]; ?>" data-min-pass="<?php echo (int) $v['min_pass']; ?>">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"
                class="honeypot">

            <section class="register-step active" data-register-step="1">
                <h3>Personal information</h3>
                <div class="form-grid">
                    <div class="field full"><label for="id_number">ID Number <b
                                class="required-mark">*</b></label><input id="id_number"
                            name="id_number" placeholder="xxxx-xxxx" pattern="[0-9]{4}-[0-9]{4}"
                            value="<?php echo oldv('id_number', $old); ?>" required></div>
                    <div class="field"><label for="first_name">First Name <b
                                class="required-mark">*</b></label><input id="first_name"
                            name="first_name" placeholder="Juan" maxlength="<?php echo (int) $v[
                                'max_name'
                            ]; ?>" value="<?php echo oldv('first_name', $old); ?>" required></div>
                    <div class="field"><label for="middle_name">Middle Name <b
                                class="optional-mark">optional</b></label><input id="middle_name"
                            name="middle_name" placeholder="Dela" maxlength="<?php echo (int) $v[
                                'max_name'
                            ]; ?>" value="<?php echo oldv('middle_name', $old); ?>"></div>
                    <div class="field"><label for="family_name">Family Name <b
                                class="required-mark">*</b></label><input id="family_name"
                            name="family_name" placeholder="Cruz" maxlength="<?php echo (int) $v[
                                'max_name'
                            ]; ?>" value="<?php echo oldv('family_name', $old); ?>" required></div>
                    <div class="field"><label for="name_extension">Name Extension <b
                                class="optional-mark">optional</b></label>
                        <select id="name_extension" name="name_extension"><?php foreach (
                            ['' => 'None', 'Jr.' => 'Jr.', 'Sr.' => 'Sr.', 'Other' => 'Other']
                            as $val => $label
                        ): ?><option value="<?php echo e($val); ?>" <?php echo ($old[
    'name_extension'
] ??
    '') ===
(string) $val
    ? ' selected'
    : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select>
                        <input id="extension_other" name="extension_other" class="extension-other"
                            placeholder="I, II, III, IV, IX or X"
                            aria-label="Name extension (Other)" value="<?php echo oldv(
                                'extension_other',
                                $old,
                            ); ?>">
                    </div>
                    <div class="field"><label for="birthdate">Birthdate <b
                                class="required-mark">*</b></label><input id="birthdate" type="date"
                            name="birthdate" value="<?php echo oldv(
                                'birthdate',
                                $old,
                            ); ?>" required></div>
                    <div class="field"><label for="age">Age <b
                                class="readonly-label">calculated</b></label><input id="age"
                            name="age" readonly placeholder="From birthdate"></div>
                </div>
                <button type="button" class="wizard-next" data-wizard-next><span>Next:
                        Address</span><?php echo gc_pub_icon('arrow', 20); ?></button>
            </section>

            <section class="register-step" data-register-step="2">
                <h3>Address information</h3>
                <div class="form-grid">
                    <div class="field full"><label for="purok">Purok / Street <b
                                class="required-mark">*</b></label><input id="purok" name="purok" placeholder="Purok 8"
                            maxlength="50" value="<?php echo oldv(
                                'purok',
                                $old,
                            ); ?>" required></div>
                    <div class="field"><label for="barangay">Barangay <b
                                class="required-mark">*</b></label><input id="barangay"
                            name="barangay" placeholder="Marcos" maxlength="50" value="<?php echo oldv(
                                'barangay',
                                $old,
                            ); ?>" required></div>
                    <div class="field"><label for="city">Municipality / City <b
                                class="required-mark">*</b></label><input id="city" name="city" placeholder="Magallanes"
                            maxlength="50" value="<?php echo oldv('city', $old); ?>" required></div>
                    <div class="field"><label for="province">Province <b
                                class="required-mark">*</b></label><input id="province"
                            name="province" placeholder="Agusan del norte" maxlength="50" value="<?php echo oldv(
                                'province',
                                $old,
                            ); ?>" required></div>
                    <div class="field"><label for="country">Country <b
                                class="required-mark">*</b></label><input id="country"
                            name="country" placeholder="Philippines" value="<?php echo oldv(
                                'country',
                                $old,
                            ); ?>" required></div>
                    <div class="field"><label for="zip_code">ZIP Code <b
                                class="required-mark">*</b></label><input id="zip_code"
                            name="zip_code" placeholder="8604" inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                            value="<?php echo oldv('zip_code', $old); ?>" required></div>
                </div>
                <div class="wizard-actions">
                    <button type="button" class="wizard-back" data-wizard-back>Back</button>
                    <button type="button" class="wizard-next" data-wizard-next><span>Next:
                            Password</span><?php echo gc_pub_icon('arrow', 20); ?></button>
                </div>
            </section>

            <section class="register-step" data-register-step="3">
                <h3>Password and account</h3>
                <div class="field full" ><label for="username">Username <b
                        class="required-mark">*</b></label>
                    <div class="gc-input"><?php echo gc_pub_icon(
                        'user',
                        20,
                        'lead',
                    ); ?><input id="username" name="username" placeholder="Juan123" value="<?php echo oldv(
    'username',
    $old,
); ?>" maxlength="<?php echo (int) $v['max_user']; ?>" autocomplete="username" required></div>
                    <small id="username-status" class="field-status"></small>
                </div>
                <div class="field full"><label for="email">Email Address <b
                            class="required-mark">*</b></label>
                    <div class="gc-input"><?php echo gc_pub_icon(
                        'mail',
                        20,
                        'lead',
                    ); ?><input id="email" type="email" name="email" placeholder="juan@gmail.com" value="<?php echo oldv(
    'email',
    $old,
); ?>" maxlength="100" autocomplete="email" required></div>
                </div>
                <div class="field full"><label for="password">Password <b
                            class="required-mark">*</b></label>
                    <?php echo gc_pub_password_field(
                        'password',
                        'password',
                        'Password',
                        'new-password',
                    ); ?>
                    <div id="password-strength" class="strength-meter">Password strength: <b>Not
                            checked</b></div>
                </div>
                <div class="field full"><label for="confirm">Re-enter Password <b
                            class="required-mark">*</b></label>
                    <?php echo gc_pub_password_field(
                        'confirm',
                        'confirm',
                        'Re-enter password',
                        'new-password',
                    ); ?></div>
                <div class="wizard-actions">
                    <button type="button" class="wizard-back" data-wizard-back>Back</button>
                    <button type="button" class="wizard-next" data-wizard-next><span>Next:
                            Verification</span><?php echo gc_pub_icon('arrow', 20); ?></button>
                </div>
            </section>

            <section class="register-step" data-register-step="4">
                <h3>Questions and verification</h3>
                <fieldset class="auth-questions" data-question-set>
                    <legend>Authentication questions</legend>
                    <p class="form-help">Pick three different questions from the list and answer each one. You will need them if you forget your password.</p>
                    <?php foreach ([1, 2, 3] as $slot): ?>
                    <div class="auth-question">
                        <label for="question_<?php echo $slot; ?>">Question <?php echo $slot; ?> <b class="required-mark">*</b></label>
                        <select id="question_<?php echo $slot; ?>" name="question_<?php echo $slot; ?>" data-question-select required>
                            <option value="">Choose a question</option>
                            <?php foreach ($questionBank as $no => $text): ?>
                            <option value="<?php echo $no; ?>"<?php echo (int) ($old['question_' . $slot] ?? 0) === $no
                                ? ' selected'
                                : ''; ?>><?php echo e($text); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="answer_<?php echo $slot; ?>">Your answer <b class="required-mark">*</b></label>
                        <input id="answer_<?php echo $slot; ?>" name="answer_<?php echo $slot; ?>" maxlength="50" autocomplete="off" required>
                    </div>
                    <?php endforeach; ?>
                </fieldset>

                <label for="captcha">Security check <b class="required-mark">*</b></label>
                <div class="captcha-box"><img class="captcha-image" src="captcha_image.php"
                        alt="CAPTCHA image"><button type="button" class="captcha-refresh"
                        data-captcha-refresh>New code</button></div>
                <input id="captcha" name="captcha" inputmode="text" pattern="[A-Za-z0-9]{6}"
                    placeholder="Type the code above" autocomplete="off" required>

                <label class="checkbox-row"><input type="checkbox" name="consent" value="1" <?php echo isset(
                    $old['consent'],
                )
                    ? 'checked'
                    : ''; ?> required><span>I have read the <a href="privacy.php?return=register">Privacy
                            Notice</a> and agree to the collection and processing of my personal
                        data under RA 10173.</span></label>

                <div class="wizard-actions">
                    <button type="button" class="wizard-back" data-wizard-back>Back</button>
                    <button class="register-button" type="submit"><span>Register</span><?php echo gc_pub_icon(
                        'arrow',
                        20,
                    ); ?></button>
                </div>
            </section>
        </form>

        <div class="gc-or"><span>or</span></div>
        <p class="gc-switch">Already have an account? <a href="login.php">Login here</a></p>
    </section>
</main>
<?php gc_pub_foot(); ?>
