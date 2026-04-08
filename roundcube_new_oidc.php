<?php

// Require composer autoload for direct installs
@include __DIR__ . '/vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

    /**
     * Roundcube OIDC
     *
     * Login to roundcube with OpenID Connect provider
     *
     * @license	MIT License: <http://opensource.org/licenses/MIT>
     * @author Varun Patil
     * @category  Plugin for RoundCube WebMail
     */
    class roundcube_new_oidc extends rcube_plugin
    {
        public $task = '*';
        private $map;

        function init() {
            $this->load_config('config.inc.php.dist');
            $this->load_config('config.inc.php');
            $this->add_hook('template_object_loginform', array($this, 'loginform'));
            $this->add_hook('logout_after', array($this, 'oidc_logout'));

            // Check OIDC session on each page load
            if ($RCMAIL = rcmail::get_instance()) {
                if ($RCMAIL->task != 'login' && $RCMAIL->task != 'logout' && $RCMAIL->user && $RCMAIL->user->ID) {
                    $this->check_oidc_session();
                }
            }
        }

        function check_oidc_session() {
            $rcmail = rcmail::get_instance();
            if (!$rcmail->config->get('oidc_session_check', false)) {
                return;
            }

            $access_token = $_SESSION['oidc_access_token'] ?? null;
            if (empty($access_token)) {
                return;
            }

            // Verify token against userinfo endpoint
            $oidc_url = rtrim($rcmail->config->get('oidc_url'), '/');
            $ch = curl_init($oidc_url . '/.well-known/openid-configuration');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $config = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $userinfo_endpoint = $config['userinfo_endpoint'] ?? null;
            if (empty($userinfo_endpoint)) {
                return;
            }

            $ch = curl_init($userinfo_endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
            ]);
            $result = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 401 || $http_code === 403) {
                $rcmail->logout_actions();
                $rcmail->kill_session();
                $logout_url = $rcmail->config->get('oidc_logout_url', '');
                header('Location: ' . (!empty($logout_url) ? $logout_url : './'));
                exit;
            }
        }

        function oidc_logout($args) {
            $rcmail = rcmail::get_instance();
            $logout_url = $rcmail->config->get('oidc_logout_url', '');
            if (!empty($logout_url)) {
                header('Location: ' . $logout_url);
                exit;
            }
            return $args;
        }

        function altReturn($ERROR) {
            // Get mail object
            $RCMAIL = rcmail::get_instance();

            // Check if overridden login page
            $altLogin = $RCMAIL->config->get('oidc_login_page');

            // Include and exit
            if (isset($altLogin) && !empty($altLogin)) {
                include $altLogin;
                exit;
            }
        }

        public function loginform($content) {
            // Add the login link
            $content['content'] .= "<p> <a href='?oidc=1'> Login with OIDC </a> </p>";

            // Check if we are starting or resuming oidc auth
            if (!isset($_GET['code']) && !isset($_GET['oidc'])) {
                $RCMAIL = rcmail::get_instance();
                if ($RCMAIL->config->get('oidc_auto_redirect', false)) {
                    header('Location: ?oidc=1');
                    exit;
                }
                $this->altReturn(null);
                return $content;
            }

            // Define error for alt login
            $ERROR = '';

            // Get mail object
            $RCMAIL = rcmail::get_instance();

            // Get master password and default imap server
            $password = $RCMAIL->config->get('oidc_imap_master_password');
            $imap_server = $RCMAIL->config->get('default_host');

            // Build provider
            $oidc = new OpenIDConnectClient(
                $RCMAIL->config->get('oidc_url'),
                $RCMAIL->config->get('oidc_client'),
                $RCMAIL->config->get('oidc_secret')
            );
            $oidc->addScope($RCMAIL->config->get('oidc_scope'));

            // Get user information
            try {
                $oidc->authenticate();
                $user = json_decode(json_encode($oidc->requestUserInfo()), true);
            } catch (\Exception $e) {
                $ERROR = 'OIDC Authentication Failed <br/>' . $e->getMessage();
                $content['content'] .= "<p class='alert-danger'> $ERROR </p>";
                $this->altReturn($ERROR);
                return $content;
            }

            // Parse fields
            $uid = $user[$RCMAIL->config->get('oidc_field_uid')];
            $password = get($user[$RCMAIL->config->get('oidc_field_password')], $password);
            $imap_server = get($user[$RCMAIL->config->get('oidc_field_server')], $imap_server);

            // Check if master user is present
            $master = $RCMAIL->config->get('oidc_config_master_user');
            if ($master != '') {
                $uid .= $RCMAIL->config->get('oidc_master_user_separator') . $master;
            }

            // Trigger auth hook
            $auth = $RCMAIL->plugins->exec_hook('authenticate', array(
                'user' => $uid,
                'pass' => $password,
                'cookiecheck' => true,
                'valid'       => true,
            ));

            // Store access token for session checking
            $access_token = $oidc->getAccessToken();

            // Login to IMAP
            if ($RCMAIL->login($auth['user'], $password, $imap_server, $auth['cookiecheck'])) {
                $_SESSION['oidc_access_token'] = $access_token;
                $RCMAIL->session->remove('temp');
                $RCMAIL->session->regenerate_id(false);
                $RCMAIL->session->set_auth_cookie();
                $RCMAIL->log_login();
                $query = array();
                $redir = $RCMAIL->plugins->exec_hook('login_after', $query + array('_task' => 'mail'));
                unset($redir['abort'], $redir['_err']);
                $query = array('_action' => '');
                $OUTPUT = new rcmail_html_page();
                $redir = $RCMAIL->plugins->exec_hook('login_after', $query + array('_task' => 'mail'));
                $RCMAIL->session->set_auth_cookie();

                // Update user profile
                $iid = $RCMAIL->user->get_identity()['identity_id'];
                $claim_name = $user['name'];
                if (isset($iid) && isset($claim_name)) {
                    $RCMAIL->user->update_identity($iid, array('name' => $claim_name));
                }

                $OUTPUT->redirect($redir, 0, true);
            } else {
                $ERROR = 'IMAP authentication failed!';
                $content['content'] .= "<p class='alert-danger'> $ERROR </p>";
            }

            $this->altReturn($ERROR);
            return $content;
        }

    }

    function get(&$var, $default=null) {
        return isset($var) ? $var : $default;
    }

