#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
HIKVISION DEVICE NETWORK SDK V6.1.9.4
DS-K1T342MX-E1 - Xodimlarni Avtomatik Sinxronizatsiya Qilish
"""

import ctypes
from ctypes import *
import os
import time
import sys

# ============ SOZLAMALAR ============
DEVICE_IP = "192.168.1.104"
DEVICE_PORT = 8000
DEVICE_USER = "admin"
DEVICE_PASS = "As677677"

# SDK papkasi
SDK_DIR = r"C:\Users\muham\Desktop\salaryv1.0\modules\hik_sdk"

# DLL yo'nalishini sozlash - MUHIM!
os.environ['PATH'] = SDK_DIR + ';' + os.environ['PATH']

# SDK ni yuklash
HCNetSDK_PATH = os.path.join(SDK_DIR, "HCNetSDK.dll")

print("=" * 70)
print("🚀 Hikvision Device Network SDK V6.1.9.4")
print("   DS-K1T342MX-E1 - Xodimlarni Sinxronizatsiya Qilish")
print("=" * 70)

# SDK ni yuklash
try:
    sdk = cdll.LoadLibrary(HCNetSDK_PATH)
    print("✅ SDK muvaffaqiyatli yuklandi")
except Exception as e:
    print(f"❌ SDK yuklash xatosi: {e}")
    sys.exit(1)

# ============ SDK FUNKSIYALARINI ANIQLASH ============
try:
    # SDK ni ishga tushirish
    sdk.NET_DVR_Init()
    print("✅ SDK ishga tushirildi")
    
    # Ulanish vaqtini sozlash (5 soniya, 3 marta urinish)
    sdk.NET_DVR_SetConnectTime(5000, 3)
    print("✅ Ulanish vaqti sozlandi")
    
except Exception as e:
    print(f"⚠️ SDK sozlash xatosi: {e}")

# ============ STRUKTURALAR ============
class NET_DVR_DEVICEINFO(Structure):
    _fields_ = [
        ("sSerialNumber", c_char * 48),
        ("byAlarmInPortNum", c_byte),
        ("byAlarmOutPortNum", c_byte),
        ("byDiskNum", c_byte),
        ("byDVRType", c_byte),
        ("byChanNum", c_byte),
        ("byStartChan", c_byte),
        ("byAudioChanNum", c_byte),
        ("byIPChanNum", c_byte),
        ("byZeroChanNum", c_byte),
        ("byMainProto", c_byte),
        ("bySubProto", c_byte),
        ("bySupport", c_byte),
        ("byNetworkAbility", c_byte),
        ("res2", c_byte * 16)
    ]

class NET_DVR_EMPLOYEE_INFO(Structure):
    _fields_ = [
        ("dwSize", c_uint),
        ("byEmployeeNo", c_char * 32),
        ("byName", c_char * 64),
        ("byCardNo", c_char * 32),
        ("dwCardNum", c_int),
        ("byUserType", c_byte),
        ("byValid", c_byte),
        ("byFaceNum", c_byte),
        ("byFingerNum", c_byte),
        ("byRes", c_byte * 32)
    ]

# ============ QURILMAGA ULANISH ============
print(f"\n🔌 Qurilmaga ulanish: {DEVICE_IP}:{DEVICE_PORT}")

device_info = NET_DVR_DEVICEINFO()

# Login funksiyasini aniqlash
try:
    # V30 usulida ulanish
    sdk.NET_DVR_Login_V30.argtypes = [c_char_p, c_ushort, c_char_p, c_char_p, POINTER(NET_DVR_DEVICEINFO)]
    sdk.NET_DVR_Login_V30.restype = c_int
    
    user_id = sdk.NET_DVR_Login_V30(
        DEVICE_IP.encode(),
        DEVICE_PORT,
        DEVICE_USER.encode(),
        DEVICE_PASS.encode(),
        byref(device_info)
    )
    print("  Usul: Login_V30")
    
except AttributeError:
    # V40 usulida ulanish
    class NET_DVR_USER_LOGIN_INFO(Structure):
        _fields_ = [
            ("sDeviceAddress", c_char * 129),
            ("wPort", c_ushort),
            ("sUserName", c_char * 65),
            ("sPassword", c_char * 65),
            ("cbLoginResult", c_void_p),
            ("bUseAsynLogin", c_bool),
            ("byVerifyMode", c_byte),
            ("byRes2", c_byte * 2),
            ("byRes", c_byte * 128)
        ]
    
    class NET_DVR_DEVICEINFO_V40(Structure):
        _fields_ = [
            ("struDeviceV30", NET_DVR_DEVICEINFO),
            ("bySupportLock", c_byte),
            ("bySupportCharNum", c_byte),
            ("bySupportExtDeviceNum", c_byte),
            ("bySupportEChannels", c_byte),
            ("byNetKeyboardNum", c_byte),
            ("res", c_byte * 264)
        ]
    
    login_info = NET_DVR_USER_LOGIN_INFO()
    login_info.sDeviceAddress = DEVICE_IP.encode()
    login_info.wPort = DEVICE_PORT
    login_info.sUserName = DEVICE_USER.encode()
    login_info.sPassword = DEVICE_PASS.encode()
    login_info.bUseAsynLogin = False
    
    device_info_v40 = NET_DVR_DEVICEINFO_V40()
    
    sdk.NET_DVR_Login_V40.argtypes = [POINTER(NET_DVR_USER_LOGIN_INFO), POINTER(NET_DVR_DEVICEINFO_V40)]
    sdk.NET_DVR_Login_V40.restype = c_int
    
    user_id = sdk.NET_DVR_Login_V40(byref(login_info), byref(device_info_v40))
    print("  Usul: Login_V40")

if user_id >= 0:
    print(f"✅ Qurilmaga ulanish muvaffaqiyatli!")
    print(f"   UserID: {user_id}")
    print(f"   Serial: {device_info.sSerialNumber.decode() if device_info.sSerialNumber else 'N/A'}")
else:
    error = sdk.NET_DVR_GetLastError()
    print(f"❌ Ulanish xatosi! Kod: {error}")
    
    errors = {
        1: "SDK ni ishga tushirish xatosi",
        7: "Ulanish vaqti tugadi (qurilma IP ni tekshiring)",
        8: "Login yoki parol xato",
        9: "Ruxsat yo'q",
        11: "Qurilma topilmadi",
        17: "Foydalanuvchi topilmadi",
        19: "Ulanish allaqachon mavjud",
        23: "Qurilma band",
        29: "Parametr xato"
    }
    print(f"   Sabab: {errors.get(error, 'Noma\'lum xato')}")
    sys.exit(1)

# ============ XODIM QO'SHISH FUNKSIYASI ============
def add_employee(employee_no, name, card_no=""):
    """Xodim qo'shish"""
    emp = NET_DVR_EMPLOYEE_INFO()
    emp.dwSize = sizeof(emp)
    
    # Xodim raqami (32 bayt)
    emp_no_bytes = str(employee_no).encode('utf-8')[:31]
    emp.byEmployeeNo = emp_no_bytes + b'\x00' * (32 - len(emp_no_bytes))
    
    # Ism (64 bayt) - lotinlashtirish
    name_latin = name
    # O'zbekcha harflarni o'zgartirish
    replacements = {
        'Ў': "O'", 'ў': "o'", 'Ғ': 'G', 'ғ': 'g', 
        'Ҳ': 'H', 'ҳ': 'h', 'Қ': 'Q', 'қ': 'q',
        'Ё': 'Yo', 'ё': 'yo', 'Ю': 'Yu', 'ю': 'yu',
        'Я': 'Ya', 'я': 'ya', 'Ш': 'Sh', 'ш': 'sh',
        'Ч': 'Ch', 'ч': 'ch'
    }
    for old, new in replacements.items():
        name_latin = name_latin.replace(old, new)
    
    name_bytes = name_latin.encode('utf-8')[:63]
    emp.byName = name_bytes + b'\x00' * (64 - len(name_bytes))
    
    # Karta raqami
    if card_no:
        card_bytes = str(card_no).encode('utf-8')[:31]
        emp.byCardNo = card_bytes + b'\x00' * (32 - len(card_bytes))
        emp.dwCardNum = 1
    else:
        emp.byCardNo = b'\x00' * 32
        emp.dwCardNum = 0
    
    emp.byUserType = 1   # 0=admin, 1=normal, 2=guest
    emp.byValid = 1      # 1=active, 0=inactive
    emp.byFaceNum = 0
    emp.byFingerNum = 0
    
    # SDK da SetEmployeeInfo funksiyasini topish
    try:
        # Standart nom
        sdk.NET_DVR_SetEmployeeInfo.argtypes = [c_int, POINTER(NET_DVR_EMPLOYEE_INFO)]
        sdk.NET_DVR_SetEmployeeInfo.restype = c_bool
        result = sdk.NET_DVR_SetEmployeeInfo(user_id, byref(emp))
    except AttributeError:
        try:
            # Alternativ nom
            sdk.NET_DVR_SetEmployeeInfo_V40.argtypes = [c_int, POINTER(NET_DVR_EMPLOYEE_INFO)]
            sdk.NET_DVR_SetEmployeeInfo_V40.restype = c_bool
            result = sdk.NET_DVR_SetEmployeeInfo_V40(user_id, byref(emp))
        except AttributeError:
            print(f"  ⚠️ SetEmployeeInfo funksiyasi topilmadi")
            return False
    
    if result:
        print(f"  ✅ {name_latin} (ID: {employee_no}) qo'shildi")
        return True
    else:
        error = sdk.NET_DVR_GetLastError() if hasattr(sdk, 'NET_DVR_GetLastError') else 0
        print(f"  ❌ {name_latin} (ID: {employee_no}) - Xato: {error}")
        return False

# ============ TEST XODIMI ============
print("\n📤 Test xodimi qo'shilmoqda...")
test_result = add_employee("TEST_001", "Test User Sinov", "1234567890")

# ============ XODIMLAR RO'YXATI ============
# Bu yerga sizning xodimlaringiz
employees = [
    {"id": "97706", "name": "Абдуллаев Ражаб"},
    {"id": "97707", "name": "Абдуллаева Зиёда"},
    {"id": "97708", "name": "Абдулҳамид Муҳамадиев"},
    # Qolgan xodimlarni qo'shing
]

print("\n📋 Xodimlar qo'shilmoqda...")
print("-" * 50)

success_count = 0
for emp in employees:
    if add_employee(emp["id"], emp["name"]):
        success_count += 1
    time.sleep(0.2)  # Qurilmani haddan oshirmaslik

print("-" * 50)
print(f"\n📊 Sinxronizatsiya natijalari:")
print(f"   ✅ Muvaffaqiyatli: {success_count} ta")
print(f"   ❌ Xatolik: {len(employees) - success_count} ta")
print(f"   📈 Jami: {len(employees)} ta")

# ============ ULANISHNI YOPISH ============
try:
    sdk.NET_DVR_Logout(user_id)
    print("\n✅ Ulanish yopildi")
except:
    pass

print("\n✅ Sinxronizatsiya yakunlandi!")