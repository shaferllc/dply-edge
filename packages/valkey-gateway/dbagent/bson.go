package main

import (
	"bufio"
	"encoding/binary"
	"fmt"
	"io"
)

// oplogTS is a BSON timestamp: seconds, then the increment within the second.
type oplogTS struct{ T, I uint32 }

func (a oplogTS) after(b oplogTS) bool { return a.T > b.T || (a.T == b.T && a.I > b.I) }

func (a oplogTS) String() string { return fmt.Sprintf("%010d-%010d", a.T, a.I) }

func parseTS(s string) (oplogTS, error) {
	var ts oplogTS
	_, err := fmt.Sscanf(s, "%d-%d", &ts.T, &ts.I)
	return ts, err
}

// readDocs calls fn for each BSON document in r (a mongodump .bson file is
// documents back to back) with the document's raw bytes.
func readDocs(r io.Reader, fn func(doc []byte) error) error {
	br := bufio.NewReaderSize(r, 1<<20)
	var size [4]byte
	for {
		if _, err := io.ReadFull(br, size[:]); err == io.EOF {
			return nil
		} else if err != nil {
			return err
		}
		n := int(binary.LittleEndian.Uint32(size[:]))
		if n < 5 || n > 64<<20 {
			return fmt.Errorf("bad BSON document size %d", n)
		}
		doc := make([]byte, n)
		copy(doc, size[:])
		if _, err := io.ReadFull(br, doc[4:]); err != nil {
			return err
		}
		if err := fn(doc); err != nil {
			return err
		}
	}
}

// docTS is the top-level "ts" timestamp of an oplog entry.
func docTS(doc []byte) (oplogTS, error) {
	p := 4
	for p < len(doc)-1 {
		typ := doc[p]
		p++
		end := p
		for end < len(doc) && doc[end] != 0 {
			end++
		}
		name := string(doc[p:end])
		p = end + 1
		if typ == 0x11 && name == "ts" && p+8 <= len(doc) {
			v := binary.LittleEndian.Uint64(doc[p : p+8])
			return oplogTS{T: uint32(v >> 32), I: uint32(v)}, nil
		}
		size, err := bsonValueSize(typ, doc[p:])
		if err != nil {
			return oplogTS{}, err
		}
		p += size
	}
	return oplogTS{}, fmt.Errorf("oplog entry without ts")
}

// bsonValueSize is the length of a value of type typ at the start of b.
func bsonValueSize(typ byte, b []byte) (int, error) {
	i32 := func() int {
		if len(b) < 4 {
			return len(b) + 1 // out of range; caught below
		}
		return int(binary.LittleEndian.Uint32(b))
	}
	cstr := func(from int) int {
		for i := from; i < len(b); i++ {
			if b[i] == 0 {
				return i + 1 - from
			}
		}
		return len(b) + 1
	}
	var n int
	switch typ {
	case 0x01, 0x09, 0x11, 0x12: // double, datetime, timestamp, int64
		n = 8
	case 0x02, 0x0D, 0x0E: // string, JS code, symbol
		n = 4 + i32()
	case 0x03, 0x04, 0x0F: // document, array, code with scope
		n = i32()
	case 0x05: // binary
		n = 5 + i32()
	case 0x06, 0x0A, 0x7F, 0xFF: // undefined, null, max key, min key
		n = 0
	case 0x07: // ObjectId
		n = 12
	case 0x08: // bool
		n = 1
	case 0x0B: // regex: two cstrings
		first := cstr(0)
		n = first + cstr(first)
	case 0x0C: // DBPointer
		n = 4 + i32() + 12
	case 0x10: // int32
		n = 4
	case 0x13: // decimal128
		n = 16
	default:
		return 0, fmt.Errorf("unknown BSON type 0x%02x", typ)
	}
	if n < 0 || n > len(b) {
		return 0, fmt.Errorf("truncated BSON value of type 0x%02x", typ)
	}
	return n, nil
}
