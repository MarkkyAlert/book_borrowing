# 📡 API Documentation

ระบบมี API endpoints สำหรับการเชื่อมต่อจากระบบภายนอก

---

## Base URL

```
{APP_URL}/api/
```

ตัวอย่าง: `http://localhost/book_borrowing/api/`

---

## Authentication

API ใช้ Session-based authentication  
ผู้ใช้ต้อง login ผ่านหน้าเว็บก่อนจึงจะเรียกใช้ API ได้

---

## Endpoints

### 1. ค้นหาหนังสือ

**Endpoint:** `GET /api/search_books.php`

**Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| q | string | Yes | คำค้นหา (ชื่อหนังสือ, ผู้แต่ง) |

**Example Request:**
```
GET /api/search_books.php?q=atomic
```

**Example Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 2,
            "title": "Atomic Habits",
            "author": "James Clear",
            "category_name": "จิตวิทยา",
            "available": 3,
            "quantity": 5
        }
    ]
}
```

---

### 2. จองหนังสือ

**Endpoint:** `POST /api/reserve_book.php`

**Authentication:** Required (must be logged in)

**Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| book_id | int | Yes | ID ของหนังสือที่ต้องการจอง |

**Example Request:**
```bash
curl -X POST http://localhost/book_borrowing/api/reserve_book.php \
  -d "book_id=2" \
  --cookie "PHPSESSID=xxx"
```

**Success Response:**
```json
{
    "success": true,
    "message": "จองสำเร็จ! กรุณามารับหนังสือ \"Atomic Habits\" ภายในวันที่ 02/02/2026"
}
```

**Error Responses:**

*Not logged in:*
```json
{
    "success": false,
    "message": "กรุณาเข้าสู่ระบบก่อนจองหนังสือ"
}
```

*Book not available:*
```json
{
    "success": false,
    "message": "หนังสือหมด ไม่สามารถจองได้"
}
```

*Already reserved:*
```json
{
    "success": false,
    "message": "คุณได้จองหนังสือเล่มนี้ไว้แล้ว กรุณารอรับหนังสือ"
}
```

---

## Error Handling

ทุก API response จะมีรูปแบบ:

**Success:**
```json
{
    "success": true,
    "data": { ... },
    "message": "..."
}
```

**Error:**
```json
{
    "success": false,
    "message": "Error description"
}
```

---

## Rate Limiting

ยังไม่มี rate limiting ใน version นี้  
แนะนำให้เพิ่มถ้าใช้งานใน production

---

## Notes

- ทุก response เป็น `Content-Type: application/json`
- ใช้ UTF-8 encoding
- Dates ในรูปแบบ `Y-m-d` หรือ `d/m/Y`
