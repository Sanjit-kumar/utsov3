<?php

namespace UtsovAPI;
use PDO;

require(dirname(__FILE__).'/utils.php'); //<- already reffered in patrons.php, not needed here
require(dirname(__FILE__).'/patrons.php');
require(dirname(__FILE__).'/registration.php');
date_default_timezone_set('America/New_York');


//// Main Section /////
    $_post = json_decode(file_get_contents("php://input"));
    
    $action = $_post->action;
    
    logMessage("**Running registration:" . $action);

    switch($action) { //Switch case for value of action
        case "test": test_function($_post); break;
        case "register" :  register($_post); break;
        case "search" :  searchpatron($_post); break;
        case "details" :  findRegistrations($_post); break;
        default:  testFunction($_post);
    }



////// End Main Section //////

    function testFunction($post){
        $return["err"] = '';
        $return["msg"] = "Test";
        $return["post"] = $post;
        echo json_encode($return);
    }
    
    //Function to search patron
    function searchpatron($post){
        $return["err"] = '';
        $return["msg"] = '';
        logMessage("**Starting search patrons");
        try
        {
            $find = $post->find;
            $return = findPatron($find);
        }
        catch(Exception $e)
        {
            $return["err"] = "Unhandled Registration Exception";
            $return["msg"] = $e->getMessage();
        }
        
        echo json_encode($return);
    }
    
    
    function findRegistrations($post){
        $return["err"] = '';
        $return["msg"] = '';
        logMessage("**Starting search registrations");
        
        try
        {
            $patronid = $post->id;
            logMessage(">>Calling search on registrations for patron:".$patronid);
            $return = getPatronRegistrations($patronid);
        }
        catch(Exception $e)
        {
            $return["err"] = "Unhandled Registration Exception";
            $return["msg"] = $e->getMessage();
        }
        
        echo json_encode($return);
    }
    
    
    
    //Function to add registration
    
    function register($post){
        $return["err"] = '';
        $return["msg"] = '';

        logMessage("**Starting registration");

        try {
            
            $db = new PDO("sqlite:" . getDBPath("register"));
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            //Common Items
            $ipaddress = get_client_ip();
            $patronid = $post->id;
            $regid = $post -> regid;
            $updatePatron = $post->updatePatron;
            $updateReg = $post->updateRegistration;
            //Patron Items
            $name1 = $post->name1;
            $name2 = $post->name2;
            $email1 = $post->email1;
            $email2 = $post->email2;
            $phone1 = $post->phone1;
            $phone2 = $post->phone2;
            $address1 = $post->address1;
            $address2 = $post->address2;
            $city = $post->city;
            $state = $post->state;
            $zip = $post->zip;
            $txDateTime = $post->txDateTime;

            
            
            //Registration Items
            $year = $post->regyear;
            $donation = $post->donamount;
            $headcount = $post->regcount;
            $message = $post->regmsg;

            logMessage(">>Patron ID:" . $patronid);

            if(empty($patronid))
            {
                logMessage(">>Patron id is empty: Adding Patron");

                //calling insert on patron.php to add new record
                $return = addPatron($name1, $name2, $email1, $email2, $phone1, $phone2, $address1, $address2, $city, $state, $zip, $ipaddress);
                //storing the returned id for the new record 
                $patronid = $return["data"];
                logMessage(">>New Patron ID:" . $patronid);
            }
            else{
                
                logMessage(">>Update flag is true : Updating Patron");
                //updating patron
                //calling update on patron.php
                $return = updatePatron($patronid, $name1, $name2, $email1, $email2, $phone1, $phone2, $address1, $address2, $city, $state, $zip, $ipaddress);
                
            }
            /*
            else{
                logMessage(">>Update flag is false : skipping patron update");
            }*/
            
           
            if(empty($return["err"])){
                
                //adding registration record
                logMessage(">>Processing Registration for patron:" . $patronid);
                
                
                if($updateReg == 'Y'){
                
                    logMessage(">>Update flag is true : Updating Registration");
                    //updating patron
                    //calling update on registration.php
                    $return = updateRegistration($regid, $patronid, $year, $headcount, $donation, $message, $ipaddress);
                
                }
                else{
                    logMessage(">>Update flag is false : Adding Registration");
                    
                    //calling insert on registration.php
                    $return = addRegistration($patronid, $year, $headcount, $donation, $message, $ipaddress);
                }

                if(empty($donation) && $donation < 0 ){
                    logMessage(">>No donation amount. Skipping adding to donation table");

                } else {
                    $payment_method = $post->payment_method;
                    $payment_id = $post->payment_id;

                    $stmtIns = $db->prepare("INSERT INTO tb_donations(donation_year, client_ip, txDateTime, email, first_name,line1, line2, city, state, postal_code, payment_method, payment_amount, payment_id, patron_id, payment_status)
                    VALUES(:donation_year, :client_ip, :txDateTime, :email, :first_name, :line1, :line2, :city, :state, :postal_code, :payment_method, :payment_amount, :payment_id, :patron_id, :payment_status)");

                    $bindVar = $stmtIns->bindParam(':client_ip', $ipaddress);
                    $bindVar = $stmtIns->bindParam(':donation_year', $year);
                    $bindVar = $stmtIns->bindParam(':txDateTime', $txDateTime);
                    $bindVar = $stmtIns->bindParam(':email', $email1);
                    $bindVar = $stmtIns->bindParam(':first_name', $name1);
                    $bindVar = $stmtIns->bindParam(':line1', $address1);
                    $bindVar = $stmtIns->bindParam(':line2', $address2);
                    $bindVar = $stmtIns->bindParam(':city', $city);
                    $bindVar = $stmtIns->bindParam(':state', $state);
                    $bindVar = $stmtIns->bindParam(':postal_code', $zip);
                    $bindVar = $stmtIns->bindParam(':payment_method', $payment_method);
                    $bindVar = $stmtIns->bindParam(':payment_amount', $donation);
                    $bindVar = $stmtIns->bindParam(':payment_id', $payment_id);
                    $bindVar = $stmtIns->bindParam(':patron_id', $patronid);
                    $bindVar = $stmtIns->bindParam(':payment_status', $message);


                    $execDonationQuery = $stmtIns->execute();

                    if($execDonationQuery){
                    $return["msg"] = "Donation information added";
                    }
                }
                        
            }
            
        }
        catch(PDOException $e)
        {
            logMessage("**DBError:" . $e->getMessage());
            $return["err"] = "DB: Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        catch(Exception $e){
            logMessage("**Error:" . $e->getMessage());
            $return["err"] = "Unhandled Registration Exception";
            $return["msg"] = $e->getMessage();
        }

        echo json_encode($return);
        
    }

  




?>
