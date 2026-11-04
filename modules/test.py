# isapi_get_method.py - GET orqali ma'lumot yozish
import requests

DEVICE_IP = "192.168.1.104"
USER = "admin"
PASS = "As677677"

def add_employee_via_get(employee_no, name):
    """GET parametrlari orqali xodim qo'shish"""
    url = f"http://{DEVICE_IP}/cgi-bin/accessControl.cgi"
    
    # Turli variantlar
    variants = [
        {"action": "add", "EmployeeNo": employee_no, "Name": name},
        {"action": "addPerson", "employeeNo": employee_no, "name": name},
        {"cmd": "add", "id": employee_no, "name": name},
        {"type": "person", "op": "add", "no": employee_no, "nm": name},
    ]
    
    for params in variants:
        try:
            response = requests.get(url, params=params, auth=(USER, PASS), timeout=5, verify=False)
            if response.status_code == 200:
                print(f"✅ {params} - ishladi!")
                return True
        except:
            pass
    
    return False

# Sinov
add_employee_via_get("TEST001", "Test User")