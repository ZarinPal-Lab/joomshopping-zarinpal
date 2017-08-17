<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_Jshopping
 * @subpackage 	trangell_Zarinpal
 * @copyright   trangell team => https://trangell.com
 * @copyright   Copyright (C) 2017 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die();

if (!class_exists ('checkHack')) {
    require_once dirname(__FILE__). '/trangell_inputcheck.php';
}


class pm_trangellzarinpal extends PaymentRoot{
    
    function showPaymentForm($params, $pmconfigs){	
        include(dirname(__FILE__)."/paymentform.php");
    }

	//function call in admin
	function showAdminFormParams($params){
		$array_params = array('transaction_end_status', 'transaction_pending_status', 'transaction_failed_status');
		foreach ($array_params as $key){
			if (!isset($params[$key])) $params[$key] = '';
		} 
		$orders = JSFactory::getModel('orders', 'JshoppingModel'); //admin model
		include(dirname(__FILE__)."/adminparamsform.php");
	}

	function showEndForm($pmconfigs, $order){
		$app	= JFactory::getApplication();
        $uri = JURI::getInstance(); 
        $pm_method = $this->getPmMethod();       
        $liveurlhost = $uri->toString(array("scheme",'host', 'port'));
        $return = $liveurlhost.SEFLink("index.php?option=com_jshopping&controller=checkout&task=step7&act=return&js_paymentclass=".$pm_method->payment_class).'&orderId='. $order->order_id;		
    	$notify_url2 = $liveurlhost.SEFLink("index.php?option=com_jshopping&controller=checkout&task=step2&act=notify&js_paymentclass=".$pm_method->payment_class."&no_lang=1");	
		//======================================================
		$Description = 'خرید محصول از فروشگاه   ';
        $CallbackURL =$return;
        $MerchantId = $pmconfigs['merchant_id'];	

		if (!isset($MerchantId) || $MerchantId == '') {	
			$app->redirect($notify_url2, '<h2>لطفا تنظیمات درگاه زیرین پال را بررسی کنید</h2>', $msgType='Error'); 
		}
		
		try {
			 $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); 	
			//$client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); // for local

			$result = $client->PaymentRequest(
				[
					'MerchantID' => $MerchantId,
					'Amount' =>  round($this->fixOrderTotal($order),0)/10 ,// Toman 
					'Description' => $Description,
					'Email' => '',
					'Mobile' => '',
					'CallbackURL' => $CallbackURL,
				]
			);
			
			$resultStatus = abs($result->Status); 
			if ($resultStatus == 100) {
				if ($pmconfigs['zaringate'] == 0){
					Header('Location: https://www.zarinpal.com/pg/StartPay/'.$result->Authority); 
				}
				else {
					Header('Location: https://www.zarinpal.com/pg/StartPay/'.$result->Authority.'‪/ZarinGate‬‬'); 
				}
				//Header('Location: https://sandbox.zarinpal.com/pg/StartPay/'.$result->Authority); // for local/
			} else {
				echo'ERR: '.$resultStatus;
			}
		}
		catch(\SoapFault $e) {
			$msg= $this->getGateMsg('error'); 
			$app	= JFactory::getApplication();
			$app->redirect($notify_url2, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}

	}
    
		function checkTransaction($pmconfigs, $order, $act){
			$app	= JFactory::getApplication();
			$jinput = $app->input;
			$uri = JURI::getInstance(); 
			$pm_method = $this->getPmMethod();       
			$liveurlhost = $uri->toString(array("scheme",'host', 'port'));
			$cancel_return = $liveurlhost.SEFLink("index.php?option=com_jshopping&controller=checkout&task=step7&act=cancel&js_paymentclass=".$pm_method->payment_class).'&orderId='. $order->order_id;	
			// $Mobile = $order->phone;
            //==================================================================
		
			$Authority = $jinput->get->get('Authority', '0', 'INT');
			$status = $jinput->get->get('Status', '', 'STRING');
					
			if (checkHack::checkString($status)){
				if ($status == 'OK') {
					try {
						$client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); 
						//$client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); // for local

						$result = $client->PaymentVerification(
							[
								'MerchantID' => $pmconfigs['merchant_id'],
								'Authority' => $Authority,
								'Amount' => round($this->fixOrderTotal($order),0)/10 // Toman 
							]
						);
						$resultStatus = abs($result->Status); 
						if ($resultStatus == 100) {
							$msg= $this->getGateMsg($resultStatus); 
							$message = "کد پیگیری".$result->RefID."<br>" ."شماره سفارش ".$order->order_id;
							$app->enqueueMessage($message, 'message');
						    saveToLog("payment.log", "Status Complete. Order ID ".$order->order_id.". message: ".$msg . " statud_code: " . $result->RefID);
							return array(1, "");
						} 
						else {
							$msg= $this->getGateMsg($resultStatus); 
							saveToLog("payment.log", "Status failed. Order ID ".$order->order_id.". message: ".$msg );
							$app->redirect($cancel_return, '<h2>'.$msg.'</h2>', $msgType='Error'); 
						}
					}
					catch(\SoapFault $e) {
						$msg= $this->getGateMsg('error'); 
						saveToLog("payment.log", "Status failed. Order ID ".$order->order_id.". message: ".$msg );
						$app->redirect($cancel_return, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
					}
			}
			else {
				$msg= $this->getGateMsg(intval(17)); 
				saveToLog("payment.log", "Status Cancelled. Order ID ".$order->order_id.". message: ".$msg );
				$app->redirect($cancel_return, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
			}
		}
		else {
			$msg= $this->getGateMsg('hck2'); 
			saveToLog("payment.log", "Status failed. Order ID ".$order->order_id.". message: ".$msg );
			$app->redirect($cancel_return, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
		}
	}


    function getUrlParams($pmconfigs){
		$app	= JFactory::getApplication();
		$jinput = $app->input;
		$oId = $jinput->get->get('orderId', '0', 'INT');
        $params = array(); 
        $params['order_id'] = $oId;
        $params['hash'] = "";
        $params['checkHash'] = 0;
        $params['checkReturnParams'] = 1;
		return $params;
    }
    
	function fixOrderTotal($order){
        $total = $order->order_total;
        if ($order->currency_code_iso=='HUF'){
            $total = round($total);
        }else{
            $total = number_format($total, 2, '.', '');
        }
    return $total;
    }

    public function getGateMsg ($msgId) {
		switch($msgId){
			case	11: $out =  'شماره کارت نامعتبر است';break;
			case	12: $out =  'موجودي کافي نيست';break;
			case	13: $out =  'رمز نادرست است';break;
			case	14: $out =  'تعداد دفعات وارد کردن رمز بيش از حد مجاز است';break;
			case	15: $out =   'کارت نامعتبر است';break;
			case	17: $out =   'کاربر از انجام تراکنش منصرف شده است';break;
			case	18: $out =   'تاريخ انقضاي کارت گذشته است';break;
			case	21: $out =   'پذيرنده نامعتبر است';break;
			case	22: $out =   'ترمينال مجوز ارايه سرويس درخواستي را ندارد';break;
			case	23: $out =   'خطاي امنيتي رخ داده است';break;
			case	24: $out =   'اطلاعات کاربري پذيرنده نامعتبر است';break;
			case	25: $out =   'مبلغ نامعتبر است';break;
			case	31: $out =  'پاسخ نامعتبر است';break;
			case	32: $out =   'فرمت اطلاعات وارد شده صحيح نمي باشد';break;
			case	33: $out =   'حساب نامعتبر است';break;
			case	34: $out =   'خطاي سيستمي';break;
			case	35: $out =   'تاريخ نامعتبر است';break;
			case	41: $out =   'شماره درخواست تکراري است';break;
			case	42: $out =   'تراکنش Sale يافت نشد';break;
			case	43: $out =   'قبلا درخواست Verify داده شده است';break;
			case	44: $out =   'درخواست Verify يافت نشد';break;
			case	45: $out =   'تراکنش Settle شده است';break;
			case	46: $out =   'تراکنش Settle نشده است';break;
			case	47: $out =   'تراکنش Settle يافت نشد';break;
			case	48: $out =   'تراکنش Reverse شده است';break;
			case	49: $out =   'تراکنش Refund يافت نشد';break;
			case	51: $out =   'تراکنش تکراري است';break;
			case	52: $out =   'سرويس درخواستي موجود نمي باشد';break;
			case	54: $out =   'تراکنش مرجع موجود نيست';break;
			case	55: $out =   'تراکنش نامعتبر است';break;
			case	61: $out =   'خطا در واريز';break;
			case	100: $out =   'تراکنش با موفقيت انجام شد.';break;
			case	111: $out =   'صادر کننده کارت نامعتبر است';break;
			case	112: $out =   'خطاي سوئيچ صادر کننده کارت';break;
			case	113: $out =   'پاسخي از صادر کننده کارت دريافت نشد';break;
			case	114: $out =   'دارنده کارت مجاز به انجام اين تراکنش نيست';break;
			case	412: $out =   'شناسه قبض نادرست است';break;
			case	413: $out =   'شناسه پرداخت نادرست است';break;
			case	414: $out =   'سازمان صادر کننده قبض نامعتبر است';break;
			case	415: $out =   'زمان جلسه کاري به پايان رسيده است';break;
			case	416: $out =   'خطا در ثبت اطلاعات';break;
			case	417: $out =   'شناسه پرداخت کننده نامعتبر است';break;
			case	418: $out =   'اشکال در تعريف اطلاعات مشتري';break;
			case	419: $out =   'تعداد دفعات ورود اطلاعات از حد مجاز گذشته است';break;
			case	421: $out =   'IP نامعتبر است';break;
			case	500: $out =   'کاربر به صفحه زرین پال رفته ولي هنوز بر نگشته است';break;
			case	'error': $out ='خطا غیر منتظره رخ داده است';break;
			case	'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
            default: $out ='خطا غیر منتظره رخ داده است';break;
		}
		return $out;
	}
}
