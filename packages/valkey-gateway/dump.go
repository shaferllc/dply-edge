package main

import (
	"bufio"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"time"
)

// A snapshot is a stream of records, one per key:
//
//	u32 key length, key, i64 absolute expiry in unix ms (0 = none),
//	u32 payload length, DUMP payload
//
// Restoring with ABSTTL means a key does not outlive its TTL while the
// tenant sleeps. Keys are streamed, so a large tenant never sits in memory.
const batch = 256

// dumpKeys writes every key on the server to w.
func dumpKeys(c *conn, w io.Writer) (int, error) {
	bw := bufio.NewWriterSize(w, 256*1024)
	cursor := "0"
	count := 0
	for {
		_ = c.SetDeadline(time.Now().Add(30 * time.Second))
		reply, err := c.do("SCAN", cursor, "COUNT", "1000")
		if err != nil {
			return count, err
		}
		page, ok := reply.([]any)
		if !ok || len(page) != 2 {
			return count, fmt.Errorf("scan: %v", reply)
		}
		cursor = string(page[0].([]byte))
		keys, _ := page[1].([]any)

		for start := 0; start < len(keys); start += batch {
			chunk := keys[start:min(start+batch, len(keys))]
			for _, k := range chunk {
				key := k.([]byte)
				c.send([]byte("DUMP"), key)
				c.send([]byte("PEXPIRETIME"), key)
			}
			if err := c.w.Flush(); err != nil {
				return count, err
			}
			for _, k := range chunk {
				payload, err := c.read()
				if err != nil {
					return count, err
				}
				expiry, err := c.read()
				if err != nil {
					return count, err
				}
				p, ok := payload.([]byte)
				if !ok {
					continue // the key expired between SCAN and DUMP
				}
				at, _ := expiry.(int64)
				if at < 0 {
					at = 0
				}
				if err := writeRecord(bw, k.([]byte), at, p); err != nil {
					return count, err
				}
				count++
			}
		}
		if cursor == "0" {
			break
		}
	}
	return count, bw.Flush()
}

func writeRecord(w *bufio.Writer, key []byte, expiry int64, payload []byte) error {
	var head [4]byte
	binary.BigEndian.PutUint32(head[:], uint32(len(key)))
	w.Write(head[:])
	w.Write(key)
	var at [8]byte
	binary.BigEndian.PutUint64(at[:], uint64(expiry))
	w.Write(at[:])
	binary.BigEndian.PutUint32(head[:], uint32(len(payload)))
	w.Write(head[:])
	_, err := w.Write(payload)
	return err
}

// restoreKeys reads records from r and RESTOREs them, pipelined.
func restoreKeys(c *conn, r io.Reader) (int, error) {
	br := bufio.NewReaderSize(r, 256*1024)
	now := time.Now().UnixMilli()
	count, pending := 0, 0
	flush := func() error {
		if pending == 0 {
			return nil
		}
		_ = c.SetDeadline(time.Now().Add(60 * time.Second))
		if err := c.w.Flush(); err != nil {
			return err
		}
		for ; pending > 0; pending-- {
			reply, err := c.read()
			if err != nil {
				return err
			}
			if e, ok := reply.(respError); ok {
				return fmt.Errorf("restore: %s", e)
			}
		}
		return nil
	}
	for {
		key, err := readChunk(br)
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return count, err
		}
		var at [8]byte
		if _, err := io.ReadFull(br, at[:]); err != nil {
			return count, err
		}
		payload, err := readChunk(br)
		if err != nil {
			return count, err
		}
		expiry := int64(binary.BigEndian.Uint64(at[:]))
		if expiry > 0 && expiry <= now {
			continue // expired while asleep
		}
		if expiry > 0 {
			c.send([]byte("RESTORE"), key, []byte(fmt.Sprint(expiry)), payload, []byte("REPLACE"), []byte("ABSTTL"))
		} else {
			c.send([]byte("RESTORE"), key, []byte("0"), payload, []byte("REPLACE"))
		}
		pending++
		count++
		if pending >= batch {
			if err := flush(); err != nil {
				return count, err
			}
		}
	}
	return count, flush()
}

func readChunk(r *bufio.Reader) ([]byte, error) {
	var head [4]byte
	if _, err := io.ReadFull(r, head[:]); err != nil {
		return nil, err
	}
	b := make([]byte, binary.BigEndian.Uint32(head[:]))
	_, err := io.ReadFull(r, b)
	return b, err
}
