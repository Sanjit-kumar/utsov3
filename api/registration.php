<?php

namespace UtsovAPI;
use PDO;

//require(dirname(__FILE__).'/utils.php');//

//// Main Section /////
    //$_post = json_decode(file_get_contents("php://input"));


    //Function to return  list of registrations by year

    function getRegistrationList($year){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("register"));

            $stmt = $db->prepare('SELECT * FROM tb_registration WHERE year = :year ORDER BY year');
            
            $bindVar = $stmt->bindParam(':year', $year);

            if($bindVar)
            {
                $num = 0;
                $stmt->execute();
                $result = $stmt->fetchAll();
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
            else{
                $return["err"] = "DB:Registration Bind Failed";
                $return["msg"] = $stmt->errorInfo();
            }
        }
        catch(PDOException $e)
        {
            $return["err"] = "DB:Resgistration Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        
        return $return;
    }

    //Function to find Registrations for Patron
    function getPatronRegistrations($patronid){
        $return["err"] = '';
        $return["msg"] = '';
        $arr = array();
        logMessage(">>>>Retrieving Registration records for:".$patronid);
        try {
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("patron"));
            
            $stmt = $db->prepare('SELECT * FROM tb_registration WHERE patron_id = :patronid ORDER BY year DESC, date DESC');
            
            $bindVar = $stmt->bindParam(':patronid', $patronid);
            
            if($bindVar)
            {
                $num = 0;
                $stmt->execute();
                $result = $stmt->fetchAll();
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
            else{
                $return["err"] = "DB:Registration Bind Failed";
                $return["msg"] = $stmt->errorInfo();
                logMessage(">>**Error: Failed Retrieving Registration records");
                logMessage($stmt->errorInfo());
            }
        }
        catch(PDOException $e)
        {
            $return["err"] = "DB:Registration Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        
        return $return;
    }

   
   

    //Function to add registration
    function addRegistration($patronid, $year, $headcount, $donation, $message, $ipaddress){
        $return["err"] = '';
        $return["msg"] = '';
        logMessage(">>>>Adding New Registration Record");
        try {
            
                //adding registration record
                logMessage(">>>>Adding Registration record for:" . $patronid);
                /*** connect to SQLite database ***/
                $db = new PDO("sqlite:" . getDBPath("register"));
    
                $stmtIns = $db->prepare("INSERT INTO tb_registration(patron_id, year, date, donation, headcount, message, ipaddress)
                    VALUES(:patron, :year, :date, :donation, :headcount, :msg, :ipadd)");
                
                $bindVar = $stmtIns->bindParam(':patron', $patronid);
                $bindVar = $stmtIns->bindParam(':year', $year);
                $bindVar = $stmtIns->bindParam(':date', $date);
                $bindVar = $stmtIns->bindParam(':donation', $donation);
                $bindVar = $stmtIns->bindParam(':headcount', $headcount);
                $bindVar = $stmtIns->bindParam(':msg', $message);
                $bindVar = $stmtIns->bindParam(':ipadd', $ipaddress);
    
                // binding values
                $date = date("Ymd:His");
                    
                if($bindVar)
                {
                    //inserting new registration
                    $exec = $stmtIns->execute();
    
                    if($exec){
                        $return["msg"] = "NEW ROW ADDED";
                        logMessage(">>>>New Registration insert Success:");
                    }
                    else{
                        logMessage(">>**Error registration insert failed:".$stmtIns->errorInfo());
                        $return["err"] = "DB: Execute Failed";
                        $return["msg"] = $stmtIns->errorInfo();
                    }
                }
                else{
                    logMessage(">>**Error registration bind failed:".$stmtIns->errorInfo());
                    $return["err"] = "DB: Bind Failed";
                    $return["msg"] = $stmtIns->errorInfo();
                }
    
                //closing DB
                $db = NULL;
           
        }
        catch(PDOException $e)
        {
            logMessage(">>**DBError:" . $e->getMessage());
            $return["err"] = "DB:Registration: Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        catch(Exception $ex)
        {
            logMessage(">>**Error:" . $ex->getMessage());
            $return["err"] = "Error:Registration: Unhandled Exception";
            $return["msg"] = $e->getMessage();
        }
        logMessage(">>>>Registration insert Complete");
        return $return;
     }
     
     
     //Function to update registration

    function updateRegistration($id, $patronid, $year, $headcount, $donation, $message, $ipaddress){
        $return["err"] = '';
        $return["msg"] = '';
        logMessage(">>>>Updating registration for patron: ".$patronid." Reg ID:".$id);
        try {
            
            /*** connect to SQLite database ***/
            $db = new PDO("sqlite:" . getDBPath("register"));
           
/*            $stmt = $db->prepare("UPDATE tb_registration SET date = :date, donation = :donation, headcount = :headcount, message = :message, ipaddress = :ipadd WHERE patron_id = :patronid AND year = :year");
*/
            $stmt = $db->prepare("UPDATE tb_registration SET patron_id = :patronid, year = :year, date = :date, donation = :donation, headcount = :headcount, message = :message, ipaddress = :ipadd WHERE id = :id");

            $bindVar = $stmt->bindParam(':id', $id);
            $bindVar = $stmt->bindParam(':patronid', $patronid);
            $bindVar = $stmt->bindParam(':year', $year);
            $bindVar = $stmt->bindParam(':date', $date);
            $bindVar = $stmt->bindParam(':donation', $donation);
            $bindVar = $stmt->bindParam(':headcount', $headcount);
            $bindVar = $stmt->bindParam(':message', $message);
            $bindVar = $stmt->bindParam(':ipadd', $ipaddress);

            $date = date("Ymd:His");
            
            
            if($bindVar)
            {
                // updating row
                $exec = $stmt->execute();

                if($exec){
                    
                    logMessage(">>>>Registration update Success:");
                    $return["msg"] = "REGISTRATION ROW UPDATE SUCCESS";
                }
                else{
                    logMessage(">>**Error: Update failed: ".implode("|", $stmt->errorInfo()));
                    $return["err"] = "DB:Registration Update Failed";
                    $return["msg"] = $stmt->errorInfo();
                    logMessage($stmt->debugDumpParams());
                }
            }
            else{
                logMessage(">>**Error: Bind failed: ".implode("|", $stmt->errorInfo()));
                $return["err"] = "DB:Registration Bind Failed";
                $return["msg"] = $stmt->errorInfo();

            }

            //closing DB
            $db = NULL;
        }
        catch(PDOException $e)
        {
            logMessage("**DBError:" . $e->getMessage());
            $return["err"] = "DB:Registration: Unhandled PDO Exception";
            $return["msg"] = $e->getMessage();
        }
        catch(Exception $ex)
        {
            logMessage("**Error:" . $ex->getMessage());
            $return["err"] = "Error:Registration: Unhandled Exception";
            $return["msg"] = $e->getMessage();
        }
        logMessage(">>>>Registration update Complete");
        return $return;
     }
     
?>
