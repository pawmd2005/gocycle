<?php
require_once 'session.php';
require_once __DIR__ . '/public_layout.php';

$registrationReturn = 'register.php?resume=1';
gc_pub_head('GoCycle | Privacy Notice', 'register', true, 'privacy-page is-auth');
?>
<main class="gc-stage is-single is-wide-single privacy-stage">
    <article class="gc-card gc-prose privacy-card" aria-labelledby="privacy-title">
        <a class="privacy-return" href="<?php echo htmlspecialchars(
            $registrationReturn,
            ENT_QUOTES,
            'UTF-8',
        ); ?>"><span aria-hidden="true">←</span> Back to registration</a>
        <header class="privacy-header">
            <div class="privacy-badge"><?php echo gc_pub_icon('shield', 28); ?></div>
            <p class="privacy-eyebrow">GOCYCLE · YOUR INFORMATION</p>
            <h1 id="privacy-title">Privacy Notice</h1>
            <p class="gc-sub">Last updated: November 10, 2026</p>
            <p class="privacy-lead">This notice explains what information this GoCycle project
                collects, why it is used, how it is protected, and how you can ask questions about
                it. Please read it before you agree during registration.</p>
        </header>

        <aside class="privacy-draft-note" role="note">
            <strong>Returning to your form?</strong>
            <span>Your registration draft remains in this browser tab while you read. Passwords,
                security answers, and CAPTCHA entries are not saved in that draft.</span>
        </aside>

        <section class="privacy-section">
            <h2><span>01</span> Information we collect</h2>
            <div class="privacy-info-grid">
                <div>
                    <h3>Identity and contact</h3>
                    <p>Your ID number, name, birthdate and calculated age, address, email address,
                        and username are used to create and manage your account.</p>
                </div>
                <div>
                    <h3>Sign-in and recovery</h3>
                    <p>The system stores a password hash, hashes of your security answers, and
                        one-time-code hashes with expiry and usage status. Codes themselves are sent
                        to your registered email for verification.</p>
                </div>
                <div>
                    <h3>Ride bookings</h3>
                    <p>If you use booking features, the system records the ride date, time, booking
                        status, and account linked to the reservation.</p>
                </div>
                <div>
                    <h3>Security records</h3>
                    <p>Authentication events may include the username entered, event time, IP
                        address, browser/device user-agent, and a short event detail. These records
                        support abuse detection and troubleshooting.</p>
                </div>
            </div>
        </section>

        <section class="privacy-section">
            <h2><span>02</span> How we use information</h2>
            <ul class="privacy-list">
                <li>Create and verify your account, and support sign-in and password recovery.</li>
                <li>Send verification or recovery codes to the email address on your account.</li>
                <li>Create, display, and manage your ride bookings.</li>
                <li>Detect repeated failed sign-ins, protect accounts, investigate technical issues,
                    and maintain an audit trail.</li>
            </ul>
            <p>Registration requires you to be at least 18 years old. Information is used for the
                GoCycle service and its security; this demo does not use it for advertising or sell
                it.</p>
        </section>

        <section class="privacy-section">
            <h2><span>03</span> Storage, access, and sharing</h2>
            <p>Account records, booking records, and security logs are stored in the configured
                GoCycle database. Passwords are processed using PHP password hashing; security
                answers and one-time codes are stored as hashes rather than readable values.
                Administrators with authorized database access may access information needed to
                operate the service and investigate security issues.</p>
            <p>The configured email/SMTP service processes your email address and the message
                containing a one-time code. This project is configured for Gmail SMTP by default;
                the project operator may choose another SMTP provider. Hosting, database, and email operators may process information only as
                needed to run the configured service. GoCycle does not include advertising trackers
                or analytics in this demo.</p>
        </section>

        <section class="privacy-section">
            <h2><span>04</span> Browser storage and security</h2>
            <p>GoCycle uses a session cookie to keep you signed in. During registration, a limited
                form draft is kept in your browser's session storage so the same-tab Privacy Notice
                link can return you to your in-progress form. The draft excludes passwords, security
                answers, and CAPTCHA values. It is removed when successful registration reaches the
                verification step, and normally ends when that browser tab's session ends.</p>
            <p>No online service can promise absolute security. Use a unique password, keep
                verification codes private, sign out on shared devices, and tell the project
                administrator if you notice activity you did not make.</p>
        </section>

        <section class="privacy-section">
            <h2><span>05</span> Retention and your choices</h2>
            <p>Records are kept for account, booking, and security purposes. This demo does not
                currently provide automatic account deletion or a published retention schedule;
                expired or used code records and security logs may remain in the database until an
                administrator removes them. The operator should set a retention period before using
                this system with real personal information.</p>
            <p>You may ask the responsible organization about access to or correction of your
                information, or make a request to object to, restrict, or remove information where
                applicable under the Data Privacy Act of 2012 (Republic Act No. 10173). Requests
                must be reviewed against service, security, and legal requirements.</p>
        </section>

        <section class="privacy-section privacy-contact">
            <h2><span>06</span> Who to contact</h2>
            <p>This copy is configured as a course/demo project and does not have an official
                organization name, privacy officer, or contact email configured. Before real-world
                use, the organization operating GoCycle should identify its personal information
                controller and publish its privacy officer/contact details here. For this demo,
                contact the GoCycle project administrator through the channel provided by your
                school or project owner.</p>
            <p class="privacy-disclaimer">This notice describes the current demo application and is
                not legal advice. The responsible organization should review and complete it before
                collecting real personal information.</p>
        </section>

        <div class="privacy-bottom-return">
            <p>Finished reviewing? Your form draft is ready in this tab.</p>
            <a class="privacy-return privacy-return-primary" href="<?php echo htmlspecialchars(
                $registrationReturn,
                ENT_QUOTES,
                'UTF-8',
            ); ?>"><span aria-hidden="true">←</span> Back to registration</a>
        </div>
    </article>
</main>
<script>
(function () {
  if (!('IntersectionObserver' in window)) return;
  var els = document.querySelectorAll('.privacy-section, .privacy-info-grid > div, .privacy-draft-note, .privacy-bottom-return');
  els.forEach(function (el, i) { el.classList.add('pv-rv'); el.style.setProperty('--pv-d', ((i % 4) * 80) + 'ms'); });
  var io = new IntersectionObserver(function (items) {
    items.forEach(function (it) { if (it.isIntersecting) { it.target.classList.add('in'); io.unobserve(it.target); } });
  }, { threshold: 0.12 });
  els.forEach(function (el) { io.observe(el); });
})();
</script>
<?php gc_pub_foot(false); ?>
