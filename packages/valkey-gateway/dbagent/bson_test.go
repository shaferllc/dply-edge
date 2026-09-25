package main

import (
	"bytes"
	"encoding/binary"
	"testing"
)

// doc builds a BSON document from raw elements.
func doc(elems ...[]byte) []byte {
	body := bytes.Join(elems, nil)
	out := binary.LittleEndian.AppendUint32(nil, uint32(4+len(body)+1))
	return append(append(out, body...), 0)
}

func elem(typ byte, name string, value []byte) []byte {
	return append(append(append([]byte{typ}, name...), 0), value...)
}

func TestOplogTimestampsAreReadPastOtherFields(t *testing.T) {
	str := func(s string) []byte {
		return append(binary.LittleEndian.AppendUint32(nil, uint32(len(s)+1)), append([]byte(s), 0)...)
	}
	ts := binary.LittleEndian.AppendUint64(nil, uint64(1758800000)<<32|7)
	entry := doc(
		elem(0x02, "op", str("i")),
		elem(0x02, "ns", str("app.notes")),
		elem(0x05, "ui", append(binary.LittleEndian.AppendUint32(nil, 16), append([]byte{4}, make([]byte, 16)...)...)),
		elem(0x03, "o", doc(elem(0x07, "_id", make([]byte, 12)), elem(0x08, "ok", []byte{1}), elem(0x0B, "re", []byte("a.*\x00i\x00")))),
		elem(0x11, "ts", ts),
		elem(0x12, "t", make([]byte, 8)),
	)
	var got []oplogTS
	stream := append(append([]byte{}, entry...), entry...)
	if err := readDocs(bytes.NewReader(stream), func(d []byte) error {
		v, err := docTS(d)
		got = append(got, v)
		return err
	}); err != nil {
		t.Fatal(err)
	}
	want := oplogTS{T: 1758800000, I: 7}
	if len(got) != 2 || got[0] != want || got[1] != want {
		t.Fatalf("got %v, want 2x %v", got, want)
	}
	parsed, err := parseTS(want.String())
	if err != nil || parsed != want || !(oplogTS{T: 1758800000, I: 8}).after(want) || want.after(want) {
		t.Fatalf("String/parseTS/after round trip failed: %v %v", parsed, err)
	}
}
