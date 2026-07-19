import time
import uuid
import hmac
import hashlib
import base64
import requests

TOKEN = "05b2b0f7dc9397caf92c33d06c306c8db7ab622cee2d1f88ea9ea134b366fa683cc90f71a1fa4e93e44142193eb954d6"
SECRET = "be220d06a84bfeedbf286bd4f126938c"

t = str(int(time.time() * 1000))
nonce = str(uuid.uuid4())

sign = base64.b64encode(
    hmac.new(
        SECRET.encode(),
        (TOKEN + t + nonce).encode(),
        hashlib.sha256
    ).digest()
).decode()

headers = {
    "Authorization": TOKEN,
    "sign": sign,
    "t": t,
    "nonce": nonce,
}

url = "https://api.switch-bot.com/v1.1/devices"

res = requests.get(url, headers=headers)
data = res.json()

print("=== 物理デバイス ===")
for d in data["body"].get("deviceList", []):
    print(f"{d['deviceName']}  ID:{d['deviceId']}  Type:{d['deviceType']}")

print("\n=== 赤外線リモコン ===")
for d in data["body"].get("infraredRemoteList", []):
    print(f"{d['deviceName']}  ID:{d['deviceId']}  Type:{d['remoteType']}")