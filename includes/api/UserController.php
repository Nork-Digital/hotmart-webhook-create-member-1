<?php
namespace Controllers;

use WP_Error;
use WP_REST_Request;
class UserController
{

    public function __construct()
    {
        $this->options = get_option('hmu_opts');
    }

    public function authorization_status_code()
    {
        $status = 401;

        if (is_user_logged_in()) {
            $status = 403;
        }

        return $status;
    }

    public function store($request)
    {
        if (!($request instanceof WP_REST_Request)) {
            return new WP_Error('rest_forbidden', esc_html__('Bad Request'), array('status' => 502));
        }

        $obj = $request->get_params();
        $errors = $this->validate($obj, [
            "hottok" => 'required',
            "prod"   => 'required',
            "first_name" => 'required',
            "last_name" => 'required',
            "email" => "required|email",
            "status"=> "required"
        ]
        );

        if (count($errors) === 0) {
            if ($obj["hottok"] == $this->options['hmu_token_required']) {
                $prod = $obj['prod'];
                switch($obj['status']) {
                    case 'approved':
                        $this->create_user($obj);
                    break;
                    case 'canceled':
                        $this->delete_user($obj);
                    break;
                    case 'chargeback':
                        $this->delete_user($obj);
                    break;
                    case 'refunded':
                        $this->delete_user($obj);
                    break;
                }
            } else {
                status_header(502);
                wp_send_json("Token Inválido!");
            }
        }

        status_header(502);
        wp_send_json($errors);
    }

    private function create_user($obj) {
        $obj['password'] = wp_generate_password(6, false);
        if (email_exists($obj["email"])) {
            //TODO: Melhorar esta abordagem, pois a intenção é mapear um reenvio do hotmart.
            // Talvez a solução seria quebrar em novos métodos e validar corretamente os possíveis cenários.

            $user = get_user_by( 'email', $obj["email"] );
            wp_set_password( $obj['password'], $user->ID );

			//global $wpdb;
			//$membership_id = '2';

			//$wpdb->update("wpxk_pmpro_memberships_users", array(
			//		'membership_id' => '2'
			//	), array(
			//		'user_id' => $user->ID
			//	)
			//);



        } else {
            $userdata = array(
                'user_login' => $obj['email'],
                'user_nicename' => $obj['first_name'],
                'first_name' => $obj['first_name'],
                'last_name' => $obj['last_name'],
                'user_email' => $obj['email'],
                'user_pass' => $obj['password'],
            );

			global $wpdb;
            wp_insert_user($userdata);
            $user = get_user_by( 'email', $obj["email"] );

            //if ($obj['prod'] == 12450) {
            //  $membership_id = '1'
            //} else if ($obj['prod'] == 12451) {
            //  $membership_id = '2'
            //} else if ($obj['prod'] == 12451) {
            //  $membership_id = '3'
            //} else {
            //  $membership_id = '4'
            //}

			$membership_id = '2';

			$wpdb->insert("wpxk_pmpro_memberships_users", array(
			   "user_id" => $user->ID,
			   "membership_id" => '2',
			   "code_id" => '0',
			   "initial_payment" => '0.00',
			   "billing_amount" => '0.00',
			   "cycle_number" => '0',
			   "cycle_period" => '',
			   "billing_limit" => '0',
			   "trial_amount" => '0.00',
			   "trial_limit" => '0',
			   "status" => 'active',
			   "startdate" => current_time('mysql', 1),
			   "enddate" => current_time('mysql', 1)
			));
        }

        $this->send_email($obj);
    }

    private function delete_user($obj) {
        require_once( ABSPATH.'wp-admin/includes/user.php' );
        if (email_exists($obj["email"])) {
            $user =  get_user_by( "email", $obj['email'] );
            //wp_delete_user( $user->ID );
            //atualizar na tabela

			global $wpdb;
			$membership_id = '0';

			$wpdb->update("wpxk_pmpro_memberships_users", array(
					'membership_id' => $membership_id
				), array(
					'user_id' => $user->ID
				)
			);

            wp_send_json("Done");
        }
    }

    private function send_email($dados)
    {
        $email_service = new \services\EmailService();

        $email_service->send_email($dados);

        status_header(200);
        wp_send_json("Done");
    }

    public function validate($data, $rules)
    {
        $errors = array();

        if (!$this->options) {
            status_header(502);
            wp_send_json("Erro de configuração do plugin!");
        }

        foreach ($rules as $key => $value) {
            $rules_value = explode("|", $value);
            foreach ($rules_value as $rule) {
                if (!is_bool($this->valid_rule($data[$key], $rule))) {
                    $errors[$key] = $this->valid_rule($data[$key], $rule);
                }
            }
        }
        return $errors;
    }

    private function valid_rule($value, $rule)
    {
        switch ($rule) {
            case "required":
                return isset($value) ? true : "campo requerido";
            case "email":
                return is_email($value) ? true : "e-mail inválido";
            case "email_exists":
                return !email_exists($value) ? true : "e-mail já cadastrado";
            default:
                return true;
        }
    }

    public static function getRoute($resource)
    {
        switch ($resource) {
            case "store":
                return rest_url("/hothook/v1/users");
            default:
                return null;
        }
    }
}
