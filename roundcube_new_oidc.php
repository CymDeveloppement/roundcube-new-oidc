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
     * @author Yann Challet (CymDeveloppement)
     * @author Varun Patil (original author)
     * @category  Plugin for RoundCube WebMail
     */
    class roundcube_new_oidc extends rcube_plugin
    {
        // Empty means the plugin is loaded for every Roundcube task.
        // A literal * breaks Roundcube task-filter regex construction.
        public $task = '';

        function init() {
            $this->load_config('config.inc.php.dist');
            $this->load_config('config.inc.php');
            $this->add_hook('template_object_loginform', array($this, 'loginform'));
            $this->add_hook('logout_after', array($this, 'oidc_logout'));
            // Runs on every authenticated request, including refresh/keep-alive
            $this->add_hook('ready', array($this, 'oidc_keep_alive'));
        }

        /**
         * Build an OIDC client using the plugin configuration.
         */
        private function oidc_client($rcmail) {
            $oidc = new OpenIDConnectClient(
                $rcmail->config->get('oidc_url'),
                $rcmail->config->get('oidc_client'),
                $rcmail->config->get('oidc_secret')
            );
            $oidc->addScope($rcmail->config->get('oidc_scope'));

            return $oidc;
        }

        /**
         * Save the refresh token encrypted and remember when it must be renewed.
         */
        private function save_oidc_token($rcmail, $refresh_token, $token_response = null) {
            if (empty($refresh_token)) {
                return;
            }

            // Renew before the access token or the SSO idle timeout expires,
            // whichever comes first (refresh_expires_in on Keycloak)
            $expires_in = 300;
            if (is_object($token_response)) {
                $lifetimes = array();
                if (!empty($token_response->expires_in)) {
                    $lifetimes[] = (int) $token_response->expires_in;
                }
                if (!empty($token_response->refresh_expires_in)) {
                    $lifetimes[] = (int) $token_response->refresh_expires_in;
                }
                if ($lifetimes) {
                    $expires_in = max(120, min($lifetimes));
                }
            }

            $_SESSION['roundcube_new_oidc_token'] = array(
                'refresh_token' => $rcmail->encrypt($refresh_token),
                'expires_at' => time() + $expires_in,
            );
        }

        /**
         * Refresh the OIDC token when it is close to expiration, which keeps
         * the provider session alive while Roundcube is in use.
         * If the provider rejects the refresh token, the OIDC session is over.
         */
        public function oidc_keep_alive($args) {
            $state = $_SESSION['roundcube_new_oidc_token'] ?? null;
            if (empty($state['refresh_token'])
                || (!empty($state['retry_after']) && $state['retry_after'] > time())) {
                return $args;
            }

            // Keep a safety margin so the token is renewed before expiry
            if (!empty($state['expires_at']) && $state['expires_at'] > time() + 60) {
                return $args;
            }

            $rcmail = rcmail::get_instance();
            $refresh_token = $rcmail->decrypt($state['refresh_token']);
            if (empty($refresh_token)) {
                return $args;
            }

            try {
                $oidc = $this->oidc_client($rcmail);
                $response = $oidc->refreshToken($refresh_token);

                if (is_object($response) && !empty($response->access_token)) {
                    $new_refresh_token = !empty($response->refresh_token)
                        ? $response->refresh_token
                        : $refresh_token;
                    $this->save_oidc_token($rcmail, $new_refresh_token, $response);
                    return $args;
                }

                // Session expired or revoked on the provider side
                if (is_object($response) && ($response->error ?? '') === 'invalid_grant') {
                    unset($_SESSION['roundcube_new_oidc_token']);
                    if ($rcmail->config->get('oidc_session_check', false)) {
                        $this->oidc_session_expired($rcmail);
                    }
                    return $args;
                }

                throw new RuntimeException('OIDC provider returned no access token'
                    . (is_object($response) && !empty($response->error) ? ' (' . $response->error . ')' : ''));
            } catch (Throwable $e) {
                // Provider unreachable: retry later without breaking the mail request
                $_SESSION['roundcube_new_oidc_token']['retry_after'] = time() + 300;
                rcmail::write_log('errors', 'OIDC token refresh failed: ' . $e->getMessage());
            }

            return $args;
        }

        /**
         * Log the user out of Roundcube after the OIDC session has ended.
         */
        private function oidc_session_expired($rcmail) {
            $rcmail->logout_actions();
            $rcmail->kill_session();

            $url = $rcmail->url(array('_task' => 'login', '_err' => 'session'));

            // Same handling as Roundcube for an invalid session in AJAX requests
            if ($rcmail->output->ajax_call || $rcmail->output->get_env('framed')) {
                $rcmail->output->show_message('sessionerror', 'error', null, true, -1);
                $rcmail->output->command('session_error', $url);
                $rcmail->output->send('iframe');
                exit;
            }

            header('Location: ' . $url);
            exit;
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
                // No auto-redirect right after logout, otherwise an active
                // provider session would log the user back in immediately
                $after_logout = rcube_utils::get_input_value('_task', rcube_utils::INPUT_GPC) === 'logout';
                if ($RCMAIL->config->get('oidc_auto_redirect', false) && !$after_logout) {
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
            // (default_host was renamed to imap_host in Roundcube 1.6)
            $password = $RCMAIL->config->get('oidc_imap_master_password');
            $imap_server = $RCMAIL->config->get('imap_host') ?: $RCMAIL->config->get('default_host');

            // Build provider
            $oidc = $this->oidc_client($RCMAIL);

            // Get user information
            try {
                $oidc->authenticate();
                $user = json_decode(json_encode($oidc->requestUserInfo()), true);
            } catch (\Exception $e) {
                $ERROR = 'OIDC Authentication Failed <br/>' . rcube::Q($e->getMessage());
                $content['content'] .= "<p class='alert-danger'> $ERROR </p>";
                $this->altReturn($ERROR);
                return $content;
            }

            // Parse fields
            $uid = $user[$RCMAIL->config->get('oidc_field_uid')] ?? null;
            $password = $user[$RCMAIL->config->get('oidc_field_password')] ?? $password;
            $imap_server = $user[$RCMAIL->config->get('oidc_field_server')] ?? $imap_server;

            if (empty($uid)) {
                $ERROR = 'OIDC Authentication Failed <br/>Missing claim: '
                    . rcube::Q($RCMAIL->config->get('oidc_field_uid'));
                $content['content'] .= "<p class='alert-danger'> $ERROR </p>";
                $this->altReturn($ERROR);
                return $content;
            }

            // Check if master user is present
            $master = $RCMAIL->config->get('oidc_config_master_user');
            if ($master != '') {
                $uid .= $RCMAIL->config->get('oidc_master_user_separator') . $master;
            }

            // Trigger auth hook
            $auth = $RCMAIL->plugins->exec_hook('authenticate', array(
                'user' => $uid,
                'pass' => $password,
                'host' => $imap_server,
                'cookiecheck' => true,
                'valid'       => true,
            ));

            // Login to IMAP
            if ($auth['valid'] && empty($auth['abort'])
                && $RCMAIL->login($auth['user'], $auth['pass'], $auth['host'], $auth['cookiecheck'])
            ) {
                $RCMAIL->session->remove('temp');
                $RCMAIL->session->regenerate_id(false);
                // Keep the refresh token for the authenticated Roundcube session
                $this->save_oidc_token($RCMAIL, $oidc->getRefreshToken(), $oidc->getTokenResponse());
                $RCMAIL->session->set_auth_cookie();
                $RCMAIL->log_login();

                // Update user profile
                $identity = $RCMAIL->user->get_identity();
                $claim_name = $user['name'] ?? null;
                if (!empty($identity['identity_id']) && !empty($claim_name)) {
                    $RCMAIL->user->update_identity($identity['identity_id'], array('name' => $claim_name));
                }

                $redir = $RCMAIL->plugins->exec_hook('login_after', array('_task' => 'mail'));
                unset($redir['abort'], $redir['_err']);
                $RCMAIL->output->redirect($redir, 0, true);
            } else {
                $ERROR = 'IMAP authentication failed!';
                $content['content'] .= "<p class='alert-danger'> $ERROR </p>";
            }

            $this->altReturn($ERROR);
            return $content;
        }

    }
