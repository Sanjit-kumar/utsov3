<?php

namespace UtsovAPI;
use PDO;

require(dirname(__FILE__).'/utils.php');

//// Main Section /////
    $_post = json_decode(file_get_contents("php://input"));
    //$request_type = $_SERVER['HTTP_X_REQUESTED_WITH'];

    //if (is_ajax()) {

    $action = $_post->action;
    switch($action) { //Switch case for value of action
        case "test": test_function($_post); break;
        case "list" :  getSponList($_post); break;
        case "add" :  addSponsor($_post); break;
        default:  testFunction($_post);
    }

    //}
    //else{ //should not be used - for test only
    //    getVolList($_post);
    //}




////// End Main Section //////

    function testFunction($post){
        $return["err"] = '';
        $return["msg"] = "Test";
        $return["post"] = $post;
        echo json_encode($return);
    }


    //Function to return  list of sponsors

    function getSponList($post){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("sponsor"));

            $result = $db->query('SELECT * FROM tb_sponsor');
            $num = 0;
            foreach($result as $row)
            {
                $arr[$num] = $row;
                $num++;
            }
            $return["data"] = $arr;
            $return ["msg"] = $num . " rows returned";

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


    //Function to add volunteers

    function addSponsor($post){
        $return["err"] = '';
        $return["msg"] = '';
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("sponsor"));

            $stmtIns = $db->prepare("INSERT INTO tb_sponsor(date, company, contact, sponsor_type, address1, address2, city, state, zip, phone, email, message, ipaddress)
                VALUES(:date, :company, :name, :stype, :add1, :add2, :city, :state, :zip, :phone, :email, :msg, :ipadd)");

            $bindVar = $stmtIns->bindParam(':date', $date);
            $bindVar = $stmtIns->bindParam(':company', $company);
            $bindVar = $stmtIns->bindParam(':name', $contact);
            $bindVar = $stmtIns->bindParam(':stype', $spontype);
            $bindVar = $stmtIns->bindParam(':add1', $address1);
            $bindVar = $stmtIns->bindParam(':add2', $address2);
            $bindVar = $stmtIns->bindParam(':city', $city);
            $bindVar = $stmtIns->bindParam(':state', $state);
            $bindVar = $stmtIns->bindParam(':zip', $zip);
            $bindVar = $stmtIns->bindParam(':phone', $phone);
            $bindVar = $stmtIns->bindParam(':email', $email);
            $bindVar = $stmtIns->bindParam(':msg', $message);
            $bindVar = $stmtIns->bindParam(':ipadd', $ipaddress);

            // inserting row
            $date = date("Ymd:His");
            $company = $post->sponcompany;
            $contact = $post->sponcontact;
            $spontype = $post->spontype;
            $address1 = $post->sponadd1;
            $address2 = $post->sponadd2;
            $city = $post->sponcity;
            $state = $post->sponstate;
            $zip = $post->sponzip;
            $phone = $post->sponphone;
            $email = $post->sponemail;
            $message = $post->sponmsg;
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
                $return["msg"] = $stmtIns->errorInfo();
            }

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

?>
