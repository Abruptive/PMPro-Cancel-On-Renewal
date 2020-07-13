<?php

namespace Abruptive\Droplets;

/**
 * PMPro Delayed Cancellation
 * 
 * Class for PMPro that only cancels a subscription when it expires (on renewal) instead of instantly.
 * 
 * WARNING: This class is heavily customized for a specific use case and needs work before it's usable.
 * It's meant to serve as a starter foundation only.
 */

class Cancellation {

	/**
	 * Constructor
	 */

	public function __construct() {

		// When users cancel (are changed to membership level 0) we give them another "cancelled" level. Can be used to downgrade someone to a free level when they cancel.
		add_action( 'pmpro_after_change_membership_level', array( $this, 'pmpro_after_change_membership_level_default_level' ), 10, 2);

		// Before cancelling, save the next_payment_timestamp to a global for later use.
		add_action( 'pmpro_before_change_membership_level', array( $this, 'store_renewal_timestamp' ), 10, 4 );

		// Give users their level back with an expiration.
		add_action( 'pmpro_after_change_membership_level', array( $this, 'my_pmpro_after_change_membership_level' ), 10, 2);

		// Change the cancellation email to reflect expiration date.
		add_filter( 'pmpro_email_body', array( $this, 'my_pmpro_email_body' ), 10, 2 );

		// Force the cancellation level to "Pro" for all membership cancellations.
		add_action( 'template_redirect', array( $this, 'force_cancel_level_to_pro' ) );

		// Redirect the user to the "Cancelled" page.
		add_action( 'pmpro_after_change_membership_level', array( $this, 'redirect_to_cancelled' ), 10, 2 );

	}

	/**
	 * Before cancelling, save the next_payment_timestamp to a global for later use.
	 */
	
	public function store_renewal_timestamp( $level_id, $user_id, $old_levels, $cancel_level ) {
		
		global $pmpro_pages, $wpdb, $pmpro_stripe_event, $pmpro_next_payment_timestamp;

		// Are we on the cancel page?
		if($level_id == 0 && (is_page($pmpro_pages['cancel']) || (is_admin() && (empty($_REQUEST['from']) || $_REQUEST['from'] != 'profile')))) {

			// Default to false. In case we're changing membership levels multiple times during this page load.
			$pmpro_next_payment_timestamp = false;

			// Get last order
			$order = new \MemberOrder();
			$order->getLastMemberOrder( $user_id, "success", $cancel_level );
			
			// Get level to check if it already has an end date
			if(! empty( $order ) && !empty( $order->membership_id ) ) {
				$level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_memberships_users WHERE membership_id = '" . $order->membership_id . "' AND user_id = '" . $user_id . "' ORDER BY id DESC LIMIT 1");
			}
			
			// Figure out the next payment timestamp
			if(empty($level) || (!empty($level->enddate) && $level->enddate != '0000-00-00 00:00:00')) {

				// Level already has an end date. set to false so we really cancel.
				$pmpro_next_payment_timestamp = false;

			} elseif( !empty( $order ) && $order->gateway == "stripe" ) {

				//if stripe, try to use the API
				if(!empty($pmpro_stripe_event)) {
					//cancel initiated from Stripe webhook
					if(!empty($pmpro_stripe_event->data->object->current_period_end)) {
						$pmpro_next_payment_timestamp = $pmpro_stripe_event->data->object->current_period_end;
					}
				} else {
					//cancel initiated from PMPro
					$pmpro_next_payment_timestamp = \PMProGateway_stripe::pmpro_next_payment("", $user_id, "success");
				}

			} elseif( !empty( $order ) && $order->gateway == "paypalexpress") {

				//if PayPal, try to use the API
				if(!empty($_POST['next_payment_date']) && $_POST['next_payment_date'] != 'N/A') {
					//cancel initiated from IPN
					$pmpro_next_payment_timestamp = strtotime($_POST['next_payment_date'], current_time('timestamp'));
				} else {
					//cancel initiated from PMPro
					$pmpro_next_payment_timestamp = \PMProGateway_paypalexpress::pmpro_next_payment("", $user_id, "success");
				}

			} else {

				//use built in PMPro function to guess next payment date
				$pmpro_next_payment_timestamp = pmpro_next_payment($user_id);

			}

		}

	}

	/**
	 * Give users their level back with an expiration.
	 */
	
	public function my_pmpro_after_change_membership_level( $level_id, $user_id ) {	

		global $pmpro_pages, $wpdb, $pmpro_next_payment_timestamp;
			
		// This "$pmpro_next_payment_timestamp" var is false if the user already cancelled.
		// On the cancel page or in admin/adminajax/webhook and not the edit user page.
		if( $pmpro_next_payment_timestamp !== false && $level_id == 0 && ( is_page( $pmpro_pages['cancel'] ) || ( is_admin() && ( empty( $_REQUEST['from'] ) || $_REQUEST['from'] != 'profile' ) ) ) ) {
			
			/*
				okay, let's give the user his old level back with an expiration based on his subscription date
			*/

			// Get last order.
			$order = new \MemberOrder();
			$order->getLastMemberOrder( $user_id, 'cancelled' );
				
			// Can't do this if we can't find the order.
			if(empty($order->id))
				return false;

			// Get the last level they had		
			$level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_memberships_users WHERE membership_id = '" . $order->membership_id . "' AND user_id = '" . $user_id . "' ORDER BY id DESC LIMIT 1");
			
			// Can't do if we can't find an old level
			if(empty($level))
				return false;								
				
			// Last payment date
			$lastdate = date("Y-m-d", $order->timestamp);
					
			/*
				next payment date
			*/

			// If stripe or PayPal, try to use the API.
			if(!empty($pmpro_next_payment_timestamp)) {
				$nextdate = $pmpro_next_payment_timestamp;
			} else {
				$nextdate = $wpdb->get_var("SELECT UNIX_TIMESTAMP('" . $lastdate . "' + INTERVAL " . $level->cycle_number . " " . $level->cycle_period . ")");
			}

			// Check if the date in the future.
			if( $nextdate - current_time( 'timestamp' ) > 0 ) {

				// Give them their level back with the expiration date set.
				$old_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_memberships_users WHERE membership_id = '" . $order->membership_id . "' AND user_id = '" . $user_id . "' ORDER BY id DESC LIMIT 1", ARRAY_A);
				$old_level['enddate'] = date("Y-m-d H:i:s", $nextdate);

				// Disable this hook so we don't loop.
				remove_action( 'pmpro_before_change_membership_level', array( $this, 'store_renewal_timestamp' ), 10, 4 );
				remove_action( 'pmpro_after_change_membership_level', array( $this, 'my_pmpro_after_change_membership_level' ), 10, 2 );

				// Disable the action to set the default level on cancels.
				remove_action( 'pmpro_after_change_membership_level', array( $this, 'pmpro_after_change_membership_level_default_level' ), 10, 2 );

				// Change level
				pmpro_changeMembershipLevel( $old_level, $user_id );
				
				// Add the action back just in case.
				add_action( 'pmpro_before_change_membership_level', array( $this, 'store_renewal_timestamp' ), 10, 4 );
				add_action( 'pmpro_after_change_membership_level', array( $this, 'my_pmpro_after_change_membership_level' ), 10, 2 );

				// Add the action back to set the default level on cancels.
				add_action( 'pmpro_after_change_membership_level', array( $this, 'pmpro_after_change_membership_level_default_level' ), 10, 2 );

			}

		}
		
	}

	/**
	 * Change the cancellation email to reflect expiration date.
	 */
	
	public function my_pmpro_email_body( $body, $email ) {

		global $pmpro_next_payment_timestamp;

		if( $email->template == 'cancel' ) {

			if( ! empty( $pmpro_next_payment_timestamp ) ) {

				global $wpdb;
	
				$user_id = $wpdb->get_var("SELECT ID FROM $wpdb->users WHERE user_email = '" . esc_sql($email->email) . "' LIMIT 1");
			
				if(!empty($user_id)) {
					if($pmpro_next_payment_timestamp - current_time( 'timestamp' ) > 0) {
						$body = str_replace( '!!membership_expiration_check!!', "<p>Your access will expire on " . date(get_option("date_format"), $pmpro_next_payment_timestamp) . ".</p>", $body );
					}
				}
	
			} else {

				$body = str_replace( '!!membership_expiration_check!!', '', $body );

			}

		}
				
		return $body;

	}

	/**
	 * When users cancel (are changed to membership level 0) we give them another "cancelled" level. 
	 * 
	 * Can be used to downgrade someone to a free level when they cancel.
	 */

	public function pmpro_after_change_membership_level_default_level( $level_id, $user_id ) {

		global $pmpro_next_payment_timestamp;

		if( !empty( $pmpro_next_payment_timestamp ) ) {
			return;
		}
		
		if( $level_id == 0 ) {

			// Change the user back to "Beginner" rank.
			$referrals = count( \Abruptive\Extensions\Connectors\AffiliateWP_Affiliate_Referrals::get_linked_customers_by_membership( 'pro', $user_id ) );
			if( $referrals < 10 ) {
				gamipress_revoke_rank_to_user( 
					$user_id, 
					gamipress_get_user_rank_id( $user_id, 'rank' ),
					1658,
				);
			}

			// Change the user back to "Inactive" generation.
			gamipress_revoke_rank_to_user( 
				$user_id, 
				gamipress_get_user_rank_id( $user_id, 'generation-level' ),
				8216,
			);

			// Change the user back to the "Free Level".
			pmpro_changeMembershipLevel( 1, $user_id );

		}

	}

	/**
	 * Force the cancellation level to "Pro" for all membership cancellations.
	 */
	
	public function force_cancel_level_to_pro() {

		global $pmpro_pages, $post;

		if( !isset( $pmpro_pages['cancel'], $post->ID ) ) {
			return;
		}

		if ( intval( $pmpro_pages['cancel'] ) === $post->ID ) {
			$_REQUEST['levelstocancel'] = 2;
		}
	
	}

	/**
	 * Redirect to the "Cancelled" page.
	 */

	public function redirect_to_cancelled( $level_id, $user_id ) {

		global $pmpro_next_payment_timestamp;

		if( !empty( $pmpro_next_payment_timestamp ) && $level_id == 2 ) {
			
			// Send an email to the member.
			$myemail = new \PMProEmail();
			$myemail->sendCancelEmail( $user_id, $level_id );

			// Send an email to the admin.
			$myemail = new \PMProEmail();
			$myemail->sendCancelAdminEmail( $user_id, $level_id );

			// Redirect to the "Cancelled" page.
			$url = home_url( 'my/subscription/cancelled/' );
			$url = add_query_arg( 'token', wp_create_nonce( 'pmpro_cancelled' ), $url );
			wp_safe_redirect( $url );
			die;

		}

	}

}

return new Cancellation;
