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
        case "list" :  getDonationList($_post); break;
        case "add" :  addDonation($_post); break;
        default:  testFunction($_post);
    }

    //}
    //else{ //should not be used - for test only
    //    getVolList($_post);
    //}




////// End Main Section //////

    function testFunction($post){
        $return["post"] = json_encode($post);
        echo json_encode($return);
    }


    //Function to return  list of volunteers

    function getDonationList($post){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("donation"));

            $result = $db->query('SELECT * FROM tb_donations');
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

?>
