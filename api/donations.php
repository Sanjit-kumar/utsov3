<?php

namespace UtsovAPI;
use PDO;

require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/patrons.php');
require(dirname(__FILE__).'/registration.php');
date_default_timezone_set('America/New_York');

//// Main Section /////
    $_post = json_decode(file_get_contents("php://input"));
    //$request_type = $_SERVER['HTTP_X_REQUESTED_WITH'];

    //$_post = file_get_contents("php://input");

    //if (is_ajax()) {
    $action = $_post->action;
    switch($action) { //Switch case for value of action
        case "test": test_function($_post); break;
        case "list" :  getDonationList($_post); break;
        case "add" :  addDonation($_post); break;
        case "getapikey" :  getApiKey($_post); break;
        case "savedonation" :  saveDonation($_post); break;
        case "sendtestemail" :  sendTestEmail($_post); break;
        case "updateticketissued" :  updateTicketIssued($_post); break;
        default:  testFunction($_post);
    }

    //}
    //else{ //should not be used - for test only
    //    getVolList($_post);
    //}




////// End Main Section //////

    function testFunction($post){
        $return["test"] = json_encode($post);
        echo json_encode($return);
    }

    function saveDonation($post) {
    $return["err"] = '';
    $return["msg"] = "";

    try {
        $db = new PDO("sqlite:" . getDBPath("register"));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $client_ip = get_client_ip();
        $donation_year = $post->donation_year;
        $txDateTime = $post->txDateTime;
        $email = $post->email;
        $first_name = $post->first_name;
        $middle_name = $post->middle_name;
        $last_name = $post->last_name;
        $payer_id = $post->payer_id;
        $line1 = $post->line1;
        $line2 = $post->line2;
        $city = $post->city;
        $state = $post->state;
        $postal_code = $post->postal_code;
        $payment_method = $post->payment_method;
        $payment_status = $post->payment_status;
        $prepayFood = $post->prepayFood;
        $payment_amount = $post->payment_amount;
        $payment_id = $post->payment_id;
        $paypal_resp = $post->paypal_resp;

        $usesNewTicketingSystem = $post->usesNewTicketingSystem;

        if($usesNewTicketingSystem){
            $adbothdays = $post->adbothdays;
            $adsat = $post->adsat;
            $adsun = $post->adsun;

            $kidbothdays = $post->kidbothdays;
            $kidsat = $post->kidsat;
            $kidsun = $post->kidsun;

            $stubothdays = $post->stubothdays;
            $stusat = $post->stusat;
            $stusun = $post->stusun;
            
            $adddon = $post->adddon;
        }

        $patrons = findPatron($email);
        $patron_id = 0;

        foreach($patrons["data"] as $patron)
        {
            $patron_id = $patron['id'];
        }

        if($patron_id==0){
            $patron = addPatron($first_name." ".$last_name, null, $email, null, null, null, $line1, $line2, $city, $state, $postal_code, $client_ip);
            $patron_id=$patron["data"];
        } else {
            updatePatron($patron_id, $first_name." ".$last_name, null, $email, null, null, null, $line1, $line2, $city, $state, $postal_code, $client_ip);
        }

        addRegistration($patron_id, $donation_year, null, $payment_amount, null, $client_ip);

        $stmtIns = $db->prepare("INSERT INTO tb_donations(donation_year, client_ip, txDateTime, email, first_name, middle_name, last_name, payer_id, line1, line2, city, state, postal_code, payment_method, payment_status, payment_amount, payment_id, patron_id, paypal_resp)
            VALUES(:donation_year, :client_ip, :txDateTime, :email, :first_name, :middle_name, :last_name, :payer_id, :line1, :line2, :city, :state, :postal_code, :payment_method, :prepayFood, :payment_amount, :payment_id, :patron_id, :paypal_resp)");

        $bindVar = $stmtIns->bindParam(':client_ip', $client_ip);
        $bindVar = $stmtIns->bindParam(':donation_year', $donation_year);
        $bindVar = $stmtIns->bindParam(':txDateTime', $txDateTime);
        $bindVar = $stmtIns->bindParam(':email', $email);
        $bindVar = $stmtIns->bindParam(':first_name', $first_name);
        $bindVar = $stmtIns->bindParam(':middle_name', $middle_name);
        $bindVar = $stmtIns->bindParam(':last_name', $last_name);
        $bindVar = $stmtIns->bindParam(':payer_id', $payer_id);
        $bindVar = $stmtIns->bindParam(':line1', $line1);
        $bindVar = $stmtIns->bindParam(':line2', $line2);
        $bindVar = $stmtIns->bindParam(':city', $city);
        $bindVar = $stmtIns->bindParam(':state', $state);
        $bindVar = $stmtIns->bindParam(':postal_code', $postal_code);
        $bindVar = $stmtIns->bindParam(':payment_method', $payment_method);
        $bindVar = $stmtIns->bindParam(':prepayFood', $prepayFood);
        $bindVar = $stmtIns->bindParam(':payment_amount', $payment_amount);
        $bindVar = $stmtIns->bindParam(':payment_id', $payment_id);
        $bindVar = $stmtIns->bindParam(':patron_id', $patron_id);
        $bindVar = $stmtIns->bindParam(':paypal_resp', $paypal_resp);

        if($usesNewTicketingSystem){

            $stmtInsTicket = $db->prepare("INSERT INTO tb_tickets(patron_id, bothdaysadult, saturdayadult, sundayadult, bothdayskid, saturdaykid, sundaykid, bothdaysstudent, saturdaystudent, sundaystudent, addtionaldonation, txDateTime, totalpayementprocessed, payment_id)
            VALUES(:patron_id, :adbothdays, :adsat, :adsun, :kidbothdays, :kidsat, :kidsun, :stubothdays, :stusat, :stusun, :adddon, :txDateTime, :payment_amount, :payment_id);");

            $bindVar1 = $stmtInsTicket->bindParam(':txDateTime', $txDateTime);
            $bindVar1 = $stmtInsTicket->bindParam(':patron_id', $patron_id);
            $bindVar1 = $stmtInsTicket->bindParam(':payment_amount', $payment_amount);
            $bindVar1 = $stmtInsTicket->bindParam(':payment_id', $payment_id);

            $bindVar1 = $stmtInsTicket->bindParam(':patron_id', $patron_id);
            
            $bindVar1 = $stmtInsTicket->bindParam(':adbothdays', $adbothdays);
            $bindVar1 = $stmtInsTicket->bindParam(':adsat', $adsat);
            $bindVar1 = $stmtInsTicket->bindParam(':adsun', $adsun);

            $bindVar1 = $stmtInsTicket->bindParam(':kidbothdays', $kidbothdays);
            $bindVar1 = $stmtInsTicket->bindParam(':kidsat', $kidsat);
            $bindVar1 = $stmtInsTicket->bindParam(':kidsun', $kidsun);

            $bindVar1 = $stmtInsTicket->bindParam(':stubothdays', $stubothdays);
            $bindVar1 = $stmtInsTicket->bindParam(':stusat', $stusat);
            $bindVar1 = $stmtInsTicket->bindParam(':stusun', $stusun);
            
            $bindVar1 = $stmtInsTicket->bindParam(':adddon', $adddon);

        }


        $exec = $stmtIns->execute();

        if($exec){
            //retrieving last inserted row for ID.
            $result = $db->query('SELECT last_insert_rowid() AS rowid FROM tb_patrons LIMIT 1');

            $r = $result->fetch();

            $lastrow = $r['rowid'];
            logMessage(">>>>New Patron record:" . $lastrow);

            if($usesNewTicketingSystem){
                $execTicketQuery = $stmtInsTicket->execute();

                if($execTicketQuery){
                    $return["msg"] = "PATRON ROW AND TICKET INFO INSERT SUCCESS";
                }
            } else{
                $return["msg"] = "PATRON ROW INSERT SUCCESS";
            }
            

            $return["data"] = $lastrow;
            $return["email_sent"] = sendEmail($post);

        }
        else{
            $return["err"] = "DB:Patrons Insert Failed";
            $return["msg"] = $stmtIns->errorInfo();
        }
        echo json_encode($return);
    }
    catch(PDOException $e)
    {
        $return["err"] = "DB:Patrons Unhandled PDO Exception";
        $return["msg"] = $e->getMessage();
    }

    return $return;
    }

    function getApiKey($post){
        $return["err"] = '';
        $return["msg"] = "";

        try {
            $db = new PDO("sqlite:" . getDBPath("register"));

            $stmt = $db->prepare('select value from tb_config where key = "paypalEnv"');


            $stmt->execute();
            $result = $stmt->fetchAll();
            foreach($result as $row)
            {
                $return["paypalEnv"] = $row['value'];
                $key = "{$row['value']}Key";
            }

            $stmt = $db->prepare('select value from tb_config where key = :key');
            $bindVar = $stmt->bindParam(':key', $key);

            $stmt->execute();
            $result = $stmt->fetchAll();
            foreach($result as $row)
            {
                $return["apiKey"] = $row['value'];
            }

            echo json_encode($return);
        }
        catch(PDOException $e)
        {
            $return["err"] = "DB:Patrons Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }

        return $return;
    }

    function IsNullOrEmptyString($question){
        return (!isset($question) || trim($question)==='');
    }

    function getParamExpression($post){
        $param_arr = array();
        $num = 0;
        $year = strftime("%Y", time());

        $year_requested = $post->formData->yearrequested;
        if(IsNullOrEmptyString($year_requested)){
            $year_requested = $year;

        }

        $param_arr[$num++] = "donation_year = '$year_requested'";

        $ticket_issued = $post->formData->ticket_issued;
        if(IsNullOrEmptyString($ticket_issued)){
            $param_arr[$num++] = "ticket_issued IS NULL";

        } else {
            $param_arr[$num++] = "ticket_issued = '$ticket_issued'";
        }


        $name = $post->formData->name;
        if(!IsNullOrEmptyString($name)){
            $param_arr[$num++] = "(first_name like '%$name%' OR middle_name like '%$name%' OR last_name like '%$name%')";
        }

        $payment_id = $post->formData->payment_id;
        if(!IsNullOrEmptyString($payment_id)){
            $param_arr[$num++] = "B.payment_id like '%$payment_id%'";
        }

        $email = $post->formData->email;
        if(!IsNullOrEmptyString($email)){
            $param_arr[$num++] = "email like '%$email%'";
        }


        return join(' and ', $param_arr);
    }
    function getDonationList($post){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        $year_arr = array();
        $year = strftime("%Y", time());

        try {
            $params = getParamExpression($post);

            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("register"));

            $result = $db->query('SELECT A.id, A.first_name, A.last_name, A.email, A.payment_amount, A.txDateTime,
            A.payment_id, A.payment_method, A.ticket_issued, A.payment_status, B.bothdaysadult, B.saturdayadult, B.sundayadult, B.bothdayskid, B.saturdaykid, B.sundaykid, B.bothdaysstudent, B.saturdaystudent, B.sundaystudent,B.addtionaldonation
             FROM tb_donations A left join tb_tickets B on A.patron_id = B.patron_id AND A.payment_id =  B.payment_id  where '.$params.' ORDER BY A.txDateTime DESC');
            $num = 0;
            foreach($result as $row)
            {
                $arr[$num] = $row;
                $num++;
            }

            $return["data"]["donors"] = $arr;
            $return ["msg"] = $num . " rows returned";

            $result = $db->query('SELECT SUM (A.payment_amount) total FROM tb_donations A left join tb_tickets B on A.patron_id = B.patron_id AND A.payment_id =  B.payment_id  where '.$params.' ORDER BY A.txDateTime DESC');
            $num = 0;
            $totalDonationAmount = 0;
            foreach($result as $row)
            {
                
                $totalDonationAmount = $row["total"];
                $num++;
            }
            $return["data"]["totalDonationAmount"] = $totalDonationAmount;

            $result = $db->query('SELECT SUM(IFNULL(B.bothdaysadult,0) + IFNULL(B.saturdayadult,0) + IFNULL(B.bothdayskid,0) + IFNULL(B.saturdaykid,0) + 
            IFNULL(B.bothdaysstudent,0) + IFNULL(B.saturdaystudent,0)) AS saturdayCount, SUM(IFNULL(B.bothdaysadult,0) + IFNULL(B.sundayadult,0) + IFNULL(B.bothdayskid,0) 
            + IFNULL(B.sundaykid,0) +IFNULL(B.bothdaysstudent,0) + IFNULL(B.sundaystudent,0)) AS sundayCount FROM tb_donations A left join tb_tickets B 
            on A.patron_id = B.patron_id AND A.payment_id =  B.payment_id  where '.$params);
            $num = 0;
            $saturdayCount = 0;
            $sundayCount = 0;
            foreach($result as $row)
            {
                
                $saturdayCount = $row["saturdayCount"];
                $sundayCount = $row["sundayCount"];
                $num++;
            }
            $return["data"]["saturdayCount"] = $saturdayCount;
            $return["data"]["sundayCount"] = $sundayCount;
            

            $result = $db->query("SELECT distinct donation_year as year FROM tb_donations where donation_year IS NOT NULL");

            $num = 0;
            $current_year_found = false;

            foreach($result as $row)
            {
                $year_arr[$num] = $row;

                if($row["year"]  == $year){
                    $current_year_found = true;
                }

                $num++;
            }
            if($current_year_found == false){
                $year_arr[$num]["year"] = $year;
            }
            $return["data"]["yearsavailable"] = $year_arr;
            /*** $return["data"]["params"] = $params; **/

            //closing DB
            $db = NULL;

            //closing DB
            $db = NULL;
        }
        catch(PDOException $e)
        {
            $return["err"] = "DB: Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }

        echo json_encode($return);
    }

    function updateTicketIssued($post){
        $return["err"] = '';
        $return["msg"] = '';
        $donationId = $post->donationId;

        try {
                /*** connect to SQLite database ***/
                $db = new PDO("sqlite:" . getDBPath("register"));
                $stmtUpd = $db->prepare("UPDATE tb_donations SET ticket_issued = 1 where id = '".$donationId."'");

                $exec = $stmtUpd->execute();

                if($exec){
                    $return["msg"] = "Ticket Issued";
                }
                else{
                    $return["err"] = "DB: Execute Failed";
                    $return["msg"] = $stmtUpd->errorInfo();
                }
             }
         catch(PDOException $e)
         {
             $return["err"] = "DB:" . $e->getMessage();
         }

         $return["data"] = $arr;
         $return["post"] = $post;
         echo json_encode($return);
    }

    //Function to add volunteers

    function addDonation($post){
        $return = '';
        $arr = array();
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("donation"));

            $stmtIns = $db->prepare("INSERT INTO tb_competition(date, competition, name, age, contact, phone, email, file_type, url, file_path, message, ipaddress)
                VALUES(:date, :compt, :name, :age, :contact, :phone, :email, :f_type, :f_url, :f_path, :msg, :ipadd)");

            $bindVar = $stmtIns->bindParam(':date', $date);
            $bindVar = $stmtIns->bindParam(':compt', $competition);
            $bindVar = $stmtIns->bindParam(':name', $name);
            $bindVar = $stmtIns->bindParam(':age', $age);
            $bindVar = $stmtIns->bindParam(':contact', $contact);
            $bindVar = $stmtIns->bindParam(':phone', $phone);
            $bindVar = $stmtIns->bindParam(':email', $email);
            $bindVar = $stmtIns->bindParam(':f_type', $file);
            $bindVar = $stmtIns->bindParam(':f_url', $url);
            $bindVar = $stmtIns->bindParam(':f_path', $path);
            $bindVar = $stmtIns->bindParam(':msg', $message);
            $bindVar = $stmtIns->bindParam(':ipadd', $ipaddress);

            // inserting row
            $date = date("Ymd:His");
            $competition = $post->competition;
            $name = $post->compname;
            $age = $post->compage;
            $contact = $post->compcontact;
            $phone = $post->compphone;
            $email = $post->compemail;
            $file = $post->compfiletype;
            $url = $post->compfileurl;
            $path = $post->compfilepath;
            $message = $post->compmsg;
            $ipaddress = get_client_ip();

            if($bindVar)
            {
                $exec = $stmtIns->execute();

                if($exec){
                    $return["msg"] = "NEW ROW ADDED";
                }
                else{
                    $return["err"] = "DB: Execute Failed";
                    $return["msg"] = $stmtIns->errorInfo();
                }
            }
            else{
                $return["err"] = "DB: Bind Failed";
            }

            //closing DB
            $db = NULL;
        }
        catch(PDOException $e)
        {
            $return["err"] = "DB:" . $e->getMessage();
        }

        $return["data"] = $arr;
        $return["post"] = $post;
        echo json_encode($return);


     }

    function sendTestEmail($post){
        $return["msg"]  = sendEmail($post);
        echo json_encode($return);
    }
    function sendEmail($post){

       $email = $post->email;
       $payment_amount = $post->payment_amount;
       $payment_id = $post->payment_id;

       $to = $email;
       $subject = "Utsov - Donation confirmation.";
       $from = "utsov@utsov.org";

       $headers = "MIME-Version: 1.0"."\r\n";
       $headers .= "Content-type: text/html; charset=UTF-8"."\r\n";
       $headers .= "From: ".$from."\r\n";
       $headers .= "Reply-To: ".$from."\r\n";
       $headers .= "Bcc: tomarkuntal@gmail.com\r\n";

       $usesNewTicketingSystem = $post->usesNewTicketingSystem;


       if($usesNewTicketingSystem){
            
            $adbothdays = $post->adbothdays;
            $adsat = $post->adsat;
            $adsun = $post->adsun;

            $kidbothdays = $post->kidbothdays;
            $kidsat = $post->kidsat;
            $kidsun = $post->kidsun;

            $stubothdays = $post->stubothdays;
            $stusat = $post->stusat;
            $stusun = $post->stusun;
            
            $adddon = $post->adddon;
            $prepayFood = $post->prepayFood;

        }

       $message = "<html>
                  <head>
                       <meta http-equiv='content-type' content='text/html;'>
                   </head>
                   <body style='word-wrap: break-word; font-size: 14px; font-family: Calibri, sans-serif;color: #1f497d'>
                   <div>Hi,
                       <br/>
                       <br/>
                       We have successfully received your payment.&nbsp;We sincerely appreciate your support.&nbsp;
                       <br/>
                   </div>
                   <ul>
                       <li>
                           Email: <a href='mailto:".$email."'>".$email."</a>
                       </li>
                       <li>
                           Total amount paid: $".$payment_amount."
                       </li>
                       <li>
                           PaymentID: ".$payment_id."

                       </li>";
        if($usesNewTicketingSystem){

            if($prepayFood){
                $message.= "<li>
                                Registration options: Dinner included
                            </li>";
            } else{
                $message.= "<li>
                                Registration options: Dinner NOT included
                            </li>";
            }
            $message.= "
                        <li>
                            Number of adults (18 years+) for both days: ".$adbothdays."
                        </li>
                        <li>
                            Number of adults (18 years+) for Saturday only: ".$adsat."
                        </li>
                        <li>
                            Number of adults (18 years+) for Sunday only: ".$adsun."
                        </li>

                        <li>
                            Number of kids (18 years+) for both days: ".$kidbothdays."
                        </li>
                        <li>
                            Number of kids (18 years+) for Saturday only: ".$kidsat."
                        </li>
                        <li>
                            Number of kids (18 years+) for Sunday only: ".$kidsun."
                        </li>


                        <li>
                            Number of students (18 years+) for both days: ".$stubothdays."
                        </li>
                        <li>
                            Number of students (18 years+) for Saturday only: ".$stusat."
                        </li>
                        <li>
                            Number of students (18 years+) for Sunday only: ".$stusun."
                        </li>
                        
                        <li>
                            Donation amount: $ ".$adddon."
                        </li>" ;
        }

        $message.="</ul>
                   <div>

                   For registation with Donation amount only tickets will NOT be issued and priority will be provided to ticket holders for event access.
                   <br>
                   Please carry an electronic or physical copy of this email for tickets.<br>
                       Regards,<br>
                       UTSOV Team
                   </div>
                   <div style='color: rgb(0, 0, 0);'>
                       <p style='color: rgb(80, 0, 80); font-family: arial, sans-serif; font-size: 12px;'>
                           <img src='http://www.utsov.org/img/Utsov_logo.png'>
                           <br/>
                           <br/>
                           <b>
                               <span style='font-size: 14pt; font-family: Arial, sans-serif;'>&nbsp;UTSOV &nbsp;Inc.</span>
                           </b>
                           <br/>
                           <i>
                               <span style='font-size: 9pt; font-family: Arial, sans-serif;'>501(c)(3) organization</span>
                           </i>
                           <br/>
                           <br/>
                           <b>
                               <span style='font-size: 10pt; font-family: Arial, sans-serif; color: rgb(102, 0, 0);'>(313) 33-UTSOV</span>
                           </b>
                           <br/>
                           <br/>
                           <span style='font-size: 9pt; font-family: Arial, sans-serif;'>
                               <a href='mailto:utsov@utsov.org' target='_blank' style='color: rgb(17, 85, 204);'>utsov@utsov.org</a>
                           </span>
                           <br/>
                           <span style='font-size: 9pt; font-family: Arial, sans-serif;'>
                               <a href='http://www.utsov.org' target='_blank' style='color: rgb(17, 85, 204);'>www.utsov.org</a>
                           </span>
                           <br/>
                           <span style='font-size: 9pt; font-family: Arial, sans-serif;'>
                               <a href='http://facebook.com/utsov.usa' target='_blank' style='color: rgb(17, 85, 204);'>facebook.com/utsov.usa</a>
                           </span>
                           <br/>
                       </p>


                       <p style='color: rgb(80, 0, 80); font-family: arial, sans-serif; font-size: 12.800000190734863px;'>
                           <span style='font-size: 10pt; font-family: 'Palatino Linotype', serif;'>
                               If you don&#8217;t want to receive further communications from
                               <span class='il'>UTSOV</span>,&nbsp;
                               please reply back to this email with &#8220;UNSUBSCRIBE&#8221; on the subject line, and your email address will be removed.
                           </span>
                       </p>

                   </div>
                   </body>
                   </html>";


          return mail($to, $subject, $message, $headers);
    }

?>
