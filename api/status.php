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
        case "list" :  getCount($_post); break;
        case "login" :  loginUser($_post); break;
        default:  testFunction($_post);
    }

////// End Main Section //////

    function testFunction($post){
        $return["err"] = '';
        $return["msg"] = "Test";
        $return["post"] = $post;
        echo json_encode($return);
    }

    //Function to return  dashboard data

    function getCount($post){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("volunteer"));

            $result = $db->query('SELECT  ( SELECT COUNT(*) FROM   tb_volunteers ) AS volcount, ( SELECT COUNT(*) FROM   tb_sponsor ) AS sponcount,  ( SELECT COUNT(*) FROM  tb_competition ) AS comcount');

            $num = 0;
            foreach($result as $row)
            {
                $arr[$num] = $row;
                $num++;
            }

            $db = new PDO("sqlite:" . getDBPath("register"));

            $result = $db->query('SELECT Sum(payment_amount) AS total_donation, COUNT(id) AS total_count FROM   tb_donations where donation_year = strftime("%Y") ');

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


    //Function to login with userid pwd

    function loginUser($post){
        $return["err"] = '';
        $return["msg"] = '';

        try
        {
            $loginId = $post->username;
            $loginPwd = $post->userpwd;

            //check if login id has data
            if(!$loginId)
            {
                throw new UtsovException("Invalid login ID. Login ID cannot be null.");
            }

            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("utsov"));

            $result = $db->query("select * from tb_users where login ='$loginId' and password ='$loginPwd'");

           /* $stmtUser = $db.prepare("SELECT * FROM tb_users where login=:loginid and password=:loginpwd");

            $bindVar = $stmtUser->bindParam(':loginid', $loginId);
            $bindVar = $stmtUser->bindParam(':loginpwd', $loginPwd);

            $result = $stmtUser->execute();*/

            $num = 0;
            foreach($result as $row)
            {
                $arr[$num] = $row;
                $num++;
            }

            //closing DB
            $db = NULL;

            if($num > 0)
            {
                $return["data"] = $arr;
                $return ["msg"] = $num . "VALID";
            }
            else
            {
                throw new UtsovException("Invalid login ID. Login ID not found.");
            }

        }
        catch(PDOException $e)
        {
            $return["err"] = "DB: Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        catch(UtsovException $e)
        {
            $return["err"] = "Login: Data Exception in input";
            $return["msg"] = $e->getMessage();
        }
        catch(Exception $e)
        {
            $return["err"] = "Login: Unhandled Exception";
            $return["msg"] = $e->getMessage();
        }

        echo json_encode($return);

     }




?>
